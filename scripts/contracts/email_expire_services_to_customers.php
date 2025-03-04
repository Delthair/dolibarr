#!/usr/bin/env php
<?php
/*
 * Copyright (C) 2005       Rodolphe Quiedeville  	<rodolphe@quiedeville.org>
 * Copyright (C) 2005-2013  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2013       Juanjo Menent           <jmenent@2byte.es>
 * Copyright (C) 2024	    Frédéric France		    <frederic.france@free.fr>
 * Copyright (C) 2024-2025	MDW						<mdeweerd@users.noreply.github.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file scripts/contracts/email_expire_services_to_customers.php
 * \ingroup facture
 * \brief Script to send a mail to customers with services to expire
 */

if (!defined('NOSESSION')) {
	define('NOSESSION', '1');
}

$sapi_type = php_sapi_name();
$script_file = basename(__FILE__);
$path = __DIR__.'/';

// Test si mode batch
$sapi_type = php_sapi_name();
if (substr($sapi_type, 0, 3) == 'cgi') {
	echo "Error: You are using PHP for CGI. To execute ".$script_file." from command line, you must use PHP for CLI mode.\n";
	exit(1);
}

if (!isset($argv[1]) || !$argv[1] || !in_array($argv[1], array('test', 'confirm')) || !in_array($argv[2], array('thirdparties', 'contacts'))) {
	print "Usage: $script_file (test|confirm) (thirdparties|contacts) [delay] [after]\n";
	print "\n";
	print "Send an email to customers to remind all contracts services to expire or expired.\n";
	print "If you choose 'test' mode, no emails are sent.\n";
	print "If you add param delay (nb of days), only services with expired date < today + delay are included.\n";
	print "If you add param after (nb of days), only services with expired date >= today + delay are included.\n";
	exit(1);
}
$mode = $argv[1];
$targettype = $argv[2];

require $path."../../htdocs/master.inc.php";
require_once DOL_DOCUMENT_ROOT.'/core/lib/functionscli.lib.php';
require_once DOL_DOCUMENT_ROOT."/core/class/CMailFile.class.php";
/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('main', 'contracts'));

// Global variables
$version = DOL_VERSION;
$error = 0;

$hookmanager->initHooks(array('cli'));


/*
 * Main
 */

@set_time_limit(0);
print "***** ".$script_file." (".$version.") pid=".dol_getmypid()." *****\n";
dol_syslog($script_file." launched with arg ".implode(',', $argv));

$now = dol_now('tzserver');
$duration_value = isset($argv[3]) ? $argv[3] : 'none';
$duration_value2 = isset($argv[4]) ? $argv[4] : 'none';

$error = 0;
print $script_file." launched with mode ".$mode." default lang=".$langs->defaultlang.(is_numeric($duration_value) ? " delay=".$duration_value : "").(is_numeric($duration_value2) ? " after=".$duration_value2 : "")."\n";

if ($mode != 'confirm') {
	$conf->global->MAIN_DISABLE_ALL_MAILS = 1;
}

$sql = "SELECT c.ref, cd.date_fin_validite, cd.total_ttc, cd.description as description, p.label as plabel,";
$sql .= " s.rowid as sid, s.nom as name, s.email, s.default_lang";
if ($targettype == 'contacts') {
	$sql .= ", sp.rowid as cid, sp.firstname as cfirstname, sp.lastname as clastname, sp.email as cemail";
}
$sql .= " FROM ".MAIN_DB_PREFIX."societe AS s";
if ($targettype == 'contacts') {
	$sql .= ", ".MAIN_DB_PREFIX."socpeople as sp";
}
$sql .= ", ".MAIN_DB_PREFIX."contrat AS c";
$sql .= ", ".MAIN_DB_PREFIX."contratdet AS cd";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product AS p ON p.rowid = cd.fk_product";
$sql .= " WHERE s.rowid = c.fk_soc AND c.rowid = cd.fk_contrat AND c.statut > 0 AND cd.statut < 5";
if (is_numeric($duration_value2)) {
	$sql .= " AND cd.date_fin_validite >= '".$db->idate(dol_time_plus_duree($now, (int) $duration_value2, "d"))."'";
}
if (is_numeric($duration_value)) {
	$sql .= " AND cd.date_fin_validite < '".$db->idate(dol_time_plus_duree($now, (int) $duration_value, "d"))."'";
}
if ($targettype == 'contacts') {
	$sql .= " AND s.rowid = sp.fk_soc";
}
$sql .= " ORDER BY";
if ($targettype == 'contacts') {
	$sql .= " sp.email, sp.rowid,";
}
$sql .= " s.email ASC, s.rowid ASC, cd.date_fin_validite ASC"; // Order by email to allow one message per email

// print $sql;
$resql = $db->query($sql);
if ($resql) {
	$num = $db->num_rows($resql);
	$i = 0;
	$oldemail = 'none';
	$oldsid = 0;
	$oldcid = 0;
	$oldlang = '';
	$total = 0;
	$foundtoprocess = 0;
	$trackthirdpartiessent = array();

	print "We found ".$num." couples (services to expire-".$targettype.") qualified\n";
	dol_syslog("We found ".$num." couples (services to expire-".$targettype.") qualified");
	$message = '';
	$oldtarget = '';

	if ($num) {
		while ($i < $num) {
			$obj = $db->fetch_object($resql);

			$newemail = empty($obj->cemail) ? $obj->email : $obj->cemail;

			// Check if this record is a break after previous one
			$startbreak = false;
			if ($newemail != $oldemail || $oldemail == 'none') {
				$startbreak = true;
			}
			if ($obj->sid && $obj->sid != $oldsid) {
				$startbreak = true;
			}
			if ($targettype == 'contacts') {
				if ($obj->cid && $obj->cid != $oldcid) {
					$startbreak = true;
				}
			}

			if ($startbreak) {
				// Break onto sales representative (new email or cid)
				if (dol_strlen($oldemail) && $oldemail != 'none' && empty($trackthirdpartiessent[$oldsid.'|'.$oldemail])) {
					sendEmailToCustomer($mode, $oldemail, $message, price2num($total), $oldlang, $oldtarget, (int) $duration_value);
					$trackthirdpartiessent[$oldsid.'|'.$oldemail] = 'contact id '.$oldcid;
				} else {
					if ($oldemail != 'none') {
						if (empty($trackthirdpartiessent[$oldsid.'|'.$oldemail])) {
							print "- No email sent for '".$oldtarget."', total: ".$total."\n";
						} else {
							print "- No email sent for '".$oldtarget."', total: ".$total." (already sent to ".$trackthirdpartiessent[$oldsid.'|'.$oldemail].")\n";
						}
					}
				}
				$oldemail = $newemail;
				$oldsid = $obj->sid;
				if ($targettype == 'contacts') {
					$oldcid = $obj->cid;
				}
				$oldlang = $obj->default_lang;
				$oldtarget = (empty($obj->cfirstname) && empty($obj->clastname)) ? $obj->name : ($obj->clastname." ".$obj->cfirstname);
				$message = '';
				$total = 0;
				$foundtoprocess = 0;
				// if (empty($newemail)) print "Warning: Customer ".$target." has no email. Notice disabled.\n";
			}

			// Define line content
			$outputlangs = new Translate('', $conf);
			$outputlangs->setDefaultLang(empty($obj->default_lang) ? $langs->defaultlang : $obj->default_lang); // By default language of customer

			// Load translation files required by the page
			$outputlangs->loadLangs(array("main", "contracts", "bills", "products"));

			if (dol_strlen($newemail)) {
				$message .= $outputlangs->trans("Contract")." ".$obj->ref.": ".$outputlangs->trans("Service")." ".dol_concatdesc($obj->plabel, $obj->description)." (".price($obj->total_ttc, 0, $outputlangs, 0, 0, -1, $conf->currency)."), ".$outputlangs->trans("DateEndPlannedShort")." ".dol_print_date($db->jdate($obj->date_fin_validite), 'day')."\n\n";
				dol_syslog("email_expire_services_to_customers.php: ".$newemail." ".$message);
				$foundtoprocess++;
			}
			print "Service to expire ".$obj->ref.", label ".dol_concatdesc($obj->plabel, $obj->description).", due date ".dol_print_date($db->jdate($obj->date_fin_validite), 'day').", customer id ".$obj->sid." ".$obj->name.", ".(isset($obj->cid) ? "contact id ".$obj->cid." ".$obj->clastname." ".$obj->cfirstname.", " : "")."email ".$newemail.", lang ".$outputlangs->defaultlang.": ";
			if (dol_strlen($newemail)) {
				print "qualified.";
			} else {
				print "disqualified (no email).";
			}
			print "\n";

			unset($outputlangs);

			$total += $obj->total_ttc;

			$i++;
		}

		// If there are remaining messages to send in the buffer
		if ($foundtoprocess) {
			if (dol_strlen($oldemail) && $oldemail != 'none' && empty($trackthirdpartiessent[$oldsid.'|'.$oldemail])) { // Break onto email (new email)
				sendEmailToCustomer($mode, $oldemail, $message, price2num($total), $oldlang, $oldtarget, (int) $duration_value);
				$trackthirdpartiessent[$oldsid.'|'.$oldemail] = 'contact id '.$oldcid;
			} else {
				if ($oldemail != 'none') {
					if (empty($trackthirdpartiessent[$oldsid.'|'.$oldemail])) {
						print "- No email sent for '".$oldtarget."', total: ".$total."\n";
					} else {
						print "- No email sent for '".$oldtarget."', total: ".$total." (already sent to ".$trackthirdpartiessent[$oldsid.'|'.$oldemail].")\n";
					}
				}
			}
		}
	} else {
		print "No services to expire found\n";
	}

	exit(0);
} else {
	dol_print_error($db);
	dol_syslog("email_expire_services_to_customers.php: Error");

	exit(1);
}

/**
 * Send email
 *
 * @param string 	$mode				Mode (test | confirm)
 * @param string 	$oldemail			Target email
 * @param string 	$message			Message to send
 * @param string 	$total				Total amount of unpayed invoices
 * @param string 	$userlang			Code lang to use for email output.
 * @param string 	$oldtarget			Target name
 * @param int 		$duration_value		duration value
 * @return int 							Int <0 if KO, >0 if OK
 */
function sendEmailToCustomer($mode, $oldemail, $message, $total, $userlang, $oldtarget, $duration_value)
{
	global $conf, $langs;

	if (getenv('DOL_FORCE_EMAIL_TO')) {
		$oldemail = getenv('DOL_FORCE_EMAIL_TO');
	}

	$newlangs = new Translate('', $conf);
	$newlangs->setDefaultLang(empty($userlang) ? getDolGlobalString('MAIN_LANG_DEFAULT', 'auto') : $userlang);
	$newlangs->load("main");
	$newlangs->load("contracts");

	if ($duration_value) {
		if ($duration_value > 0) {
			$title = $newlangs->transnoentities("ListOfServicesToExpireWithDuration", (string) $duration_value);
		} else {
			$title = $newlangs->transnoentities("ListOfServicesToExpireWithDurationNeg", (string) $duration_value);
		}
	} else {
		$title = $newlangs->transnoentities("ListOfServicesToExpire");
	}

	$subject = getDolGlobalString('SCRIPT_EMAIL_EXPIRE_SERVICES_CUSTOMERS_SUBJECT', $title);
	$sendto = $oldemail;
	$from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
	$errorsto = getDolGlobalString('MAIN_MAIL_ERRORS_TO');
	$msgishtml = -1;

	print "- Send email to '".$oldtarget."' (".$oldemail."), total: ".$total."\n";
	dol_syslog("email_expire_services_to_customers.php: send mail to ".$oldemail);

	$usehtml = 0;
	if (dol_textishtml(getDolGlobalString('SCRIPT_EMAIL_EXPIRE_SERVICES_CUSTOMERS_FOOTER'))) {
		$usehtml += 1;
	}
	if (dol_textishtml(getDolGlobalString('SCRIPT_EMAIL_EXPIRE_SERVICES_CUSTOMERS_HEADER'))) {
		$usehtml += 1;
	}

	$allmessage = '';
	if (getDolGlobalString('SCRIPT_EMAIL_EXPIRE_SERVICES_CUSTOMERS_HEADER')) {
		$allmessage .= getDolGlobalString('SCRIPT_EMAIL_EXPIRE_SERVICES_CUSTOMERS_HEADER');
	} else {
		$allmessage .= "Dear customer".($usehtml ? "<br>\n" : "\n").($usehtml ? "<br>\n" : "\n");
		$allmessage .= "Please, find a summary of the services contracted by you that are about to expire.".($usehtml ? "<br>\n" : "\n").($usehtml ? "<br>\n" : "\n");
	}
	$allmessage .= $message.($usehtml ? "<br>\n" : "\n");
	// $allmessage.= $langs->trans("Total")." = ".price($total,0,$userlang,0,0,-1,$conf->currency).($usehtml?"<br>\n":"\n");
	if (getDolGlobalString('SCRIPT_EMAIL_EXPIRE_SERVICES_CUSTOMERS_FOOTER')) {
		$allmessage .= getDolGlobalString('SCRIPT_EMAIL_EXPIRE_SERVICES_CUSTOMERS_FOOTER');
		if (dol_textishtml(getDolGlobalString('SCRIPT_EMAIL_EXPIRE_SERVICES_CUSTOMERS_FOOTER'))) {
			$usehtml += 1;
		}
	}

	$mail = new CMailFile($subject, $sendto, $from, $allmessage, array(), array(), array(), '', '', 0, $msgishtml);

	$mail->errors_to = $errorsto;

	// Send or not email
	if ($mode == 'confirm') {
		$result = $mail->sendfile();
		if (!$result) {
			print "Error sending email ".$mail->error."\n";
			dol_syslog("Error sending email ".$mail->error."\n");
		}
	} else {
		print "No email sent (test mode)\n";
		dol_syslog("No email sent (test mode)");
		$mail->dump_mail();
		$result = 1;
	}

	unset($newlangs);
	if ($result) {
		return 1;
	} else {
		return -1;
	}
}
