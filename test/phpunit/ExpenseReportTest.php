<?php
/* Copyright (C) 2010 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2023 Alexandre Janniaux   <alexandre.janniaux@gmail.com>
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
 * or see https://www.gnu.org/
 */

/**
 *      \file       test/phpunit/ExpenseReportTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test
 *      \remarks    To run this script as CLI:  phpunit filename.php
 */

global $conf,$user,$langs,$db;
//define('TEST_DB_FORCE_TYPE','mysql');	// This is to force using mysql driver
//require_once 'PHPUnit/Autoload.php';
require_once dirname(__FILE__).'/../../htdocs/master.inc.php';
require_once dirname(__FILE__).'/../../htdocs/expensereport/class/expensereport.class.php';
require_once dirname(__FILE__).'/CommonClassTest.class.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;



/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class ExpenseReportTest extends CommonClassTest
{
	/**
	 * testExpenseReportCreate
	 *
	 * @return	void
	 */
	public function testExpenseReportCreate()
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		// Create supplier order with a too low quantity
		$localobject = new ExpenseReport($db);
		$localobject->initAsSpecimen();         // Init a specimen with lines
		$localobject->status = 0;
		$localobject->fk_statut = 0;
		$localobject->date_fin = null;  // Force bad value

		$result = $localobject->create($user);
		print __METHOD__." result=".$result."\n";
		$this->assertEquals(-1, $result, "Error on test ExpenseReport create 1 : ".$localobject->error);       // must be -1 because of missing mandatory fields

		$sql = "DELETE FROM ".MAIN_DB_PREFIX."expensereport where ref=''";
		$db->query($sql);

		// Create supplier order
		$localobject2 = new ExpenseReport($db);
		$localobject2->initAsSpecimen();        // Init a specimen with lines
		$localobject2->status = 0;
		$localobject2->fk_statut = 0;

		$result = $localobject2->create($user);
		print __METHOD__." result=".$result."\n";
		$this->assertGreaterThanOrEqual(0, $result, "Error on test ExpenseReport create 2 : ".$localobject2->error);

		return $result;
	}


	/**
	 * testExpenseReportFetch
	 *
	 * @param   int $id     Id of supplier order
	 * @return  void
	 *
	 * @depends testExpenseReportCreate
	 * The depends says test is run only if previous is ok
	 */
	public function testExpenseReportFetch($id)
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$localobject = new ExpenseReport($db);
		$result = $localobject->fetch($id);

		print __METHOD__." id=".$id." result=".$result."\n";
		$this->assertLessThan($result, 0);
		return $localobject;
	}

	/**
	 * testExpenseReportValid
	 *
	 * @param   ExpenseReport $localobject     ExpenseReport
	 * @return  void
	 *
	 * @depends testExpenseReportFetch
	 * The depends says test is run only if previous is ok
	 */
	public function testExpenseReportValid($localobject)
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$result = $localobject->setValidate($user);

		print __METHOD__." id=".$localobject->id." result=".$result."\n";
		$this->assertLessThan($result, 0);
		return $localobject;
	}

	/**
	 * testExpenseReportApprove
	 *
	 * @param   ExpenseReport $localobject ExpenseReport
	 * @return  void
	 *
	 * @depends testExpenseReportValid
	 * The depends says test is run only if previous is ok
	 */
	public function testExpenseReportApprove($localobject)
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$result = $localobject->setApproved($user);

		print __METHOD__." id=".$localobject->id." result=".$result."\n";
		$this->assertLessThan($result, 0);
		return $localobject;
	}

	/**
	 * testExpenseReportCancel
	 *
	 * @param   ExpenseReport  $localobject        ExpenseReport
	 * @return  void
	 *
	 * @depends testExpenseReportApprove
	 * The depends says test is run only if previous is ok
	 */
	public function testExpenseReportCancel($localobject)
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$result = $localobject->set_cancel($user, 'Because...');

		print __METHOD__." id=".$localobject->id." result=".$result."\n";
		$this->assertLessThan($result, 0);
		return $localobject;
	}

	/**
	 * testExpenseReportOther
	 *
	 * @param   ExpenseReport $localobject     ExpenseReport
	 * @return  void
	 *
	 * @depends testExpenseReportCancel
	 * The depends says test is run only if previous is ok
	 */
	public function testExpenseReportOther($localobject)
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$result = $localobject->getSumPayments();
		print __METHOD__." id=".$localobject->id." result=".$result."\n";
		$this->assertGreaterThanOrEqual(0, $result);

		return $localobject->id;
	}

	/**
	 * testExpenseReportDelete
	 *
	 * @param   int $id     Id of order
	 * @return  void
	 *
	 * @depends testExpenseReportOther
	 * The depends says test is run only if previous is ok
	 */
	public function testExpenseReportDelete($id)
	{
		global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$localobject = new ExpenseReport($db);
		$result = $localobject->fetch($id);
		$result = $localobject->delete($user);

		print __METHOD__." id=".$id." result=".$result."\n";
		$this->assertGreaterThan(0, $result);
		return $result;
	}
}
