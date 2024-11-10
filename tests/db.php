<?php
/**
 * Fuel is a fast, lightweight, community driven PHP 5.4+ framework.
 *
 * @package    Fuel
 * @version    1.9-dev
 * @author     Fuel Development Team
 * @license    MIT License
 * @copyright  2010 - 2019 Fuel Development Team
 * @link       https://fuelphp.com
 */

namespace Fuel\Core;

/**
 * Db class tests
 *
 * @group Core
 * @group Db
 */
class Test_Db extends TestCase
{
	public static function setUpBeforeClass(): void
	{
		DB::query(
			'CREATE TABLE IF NOT EXISTS users (' .
			'id INT AUTO_INCREMENT PRIMARY KEY,' .
			'username VARCHAR(50) NOT NULL,' .
			'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,' .
			'updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP' .
			')'
		)->execute();
		DB::query('TRUNCATE TABLE users');
		$data = [
			['username' => 'alice'],
			['username' => 'bob'],
		];
		DB::insert('users')
			->columns(array_keys($data[0]))
			->values($data)
			->execute();
	}

	public function test_query() {
		$query = DB::query('SELECT * FROM users WHERE id = 1');
		$result = $query->execute()->as_array();
		$this->assertCount(1, $result);
		$this->assertSame('alice', $result[0]['username']);
	}
}
