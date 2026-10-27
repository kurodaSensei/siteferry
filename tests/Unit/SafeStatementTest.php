<?php
/**
 * Tests for the validator of statements that arrive from another site.
 *
 * @package Siteferry
 */

namespace Siteferry\Tests\Unit;

use Siteferry\Engine\Schema;
use PHPUnit\Framework\TestCase;

final class SafeStatementTest extends TestCase {

	public static function legit(): array {
		$create = 'CREATE TABLE `_sft_wp_posts` ( `ID` bigint unsigned NOT NULL AUTO_INCREMENT, `post_content` longtext COLLATE utf8mb4_unicode_520_ci NOT NULL, '
			. "`post_date` datetime NOT NULL DEFAULT '0000-00-00 00:00:00', `select` varchar(20) DEFAULT 'UNION SELECT', PRIMARY KEY (`ID`), "
			. "KEY `type_status_date` (`post_type`,`post_status`,`post_date`,`ID`) ) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci COMMENT='DATA DIRECTORY'";
		$insert = 'INSERT INTO `_sft_wp_posts` (`ID`,`post_content`,`blob`,`n`) VALUES '
			. "(1,'SELECT * FROM wp_users',0x00FF,-1.5e-7),(2,'a\\'),(1','',NULL),(3,'back\\\\\\\\','doble \"comilla\"',12),(4,'it''s','`tick`',0)";
		return array(
			'mariadb aria'  => array( 'CREATE TABLE `_sft_wp_x` ( `a` int(11) NOT NULL, PRIMARY KEY (`a`) ) ENGINE=Aria DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci PAGE_CHECKSUM=1 TRANSACTIONAL=1' ),
			'wordfence memory' => array( "CREATE TABLE `_sft_wp_wfls_role_counts` ( `serialized_roles` varbinary(255) NOT NULL, `two_factor_inactive` tinyint(1) NOT NULL, `user_count` bigint unsigned NOT NULL DEFAULT '0', PRIMARY KEY (`serialized_roles`,`two_factor_inactive`) ) ENGINE=MEMORY DEFAULT CHARSET=latin1" ),
			'mysql 8 options' => array( "CREATE TABLE `_sft_wp_y` ( `id` bigint NOT NULL ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC STATS_PERSISTENT=0 COMMENT='tabla'" ),
			'drop'   => array( 'DROP TABLE IF EXISTS `_sft_wp_posts`' ),
			'create' => array( $create ),
			'insert' => array( $insert ),
		);
	}

	public static function attacks(): array {
		return array(
			'drop of a live table after comma'  => array( 'DROP TABLE IF EXISTS `_sft_x` , `wp_posts`' ),
			'drop without a space'              => array( 'DROP TABLE IF EXISTS `_sft_x`,`wp_posts`' ),
			'insert select'                     => array( 'INSERT INTO `_sft_x` (`a`) SELECT user_pass FROM wp_users' ),
			'subquery in values'                => array( 'INSERT INTO `_sft_x` (`a`) VALUES ((SELECT user_pass FROM wp_users LIMIT 1))' ),
			'function in values'                => array( "INSERT INTO `_sft_x` (`a`) VALUES (LOAD_FILE('/etc/passwd'))" ),
			'insert with on duplicate'          => array( "INSERT INTO `_sft_x` (`a`) VALUES (1) ON DUPLICATE KEY UPDATE a = (SELECT 1)" ),
			'create select'                     => array( 'CREATE TABLE `_sft_x` (`a` int) SELECT * FROM wp_users' ),
			'create with data directory'        => array( "CREATE TABLE `_sft_x` (`a` int) ENGINE=MyISAM DATA DIRECTORY='/tmp'" ),
			'create federated'                  => array( "CREATE TABLE `_sft_x` (`a` int) ENGINE=FEDERATED CONNECTION='mysql://u@h/db/t'" ),
			'create with csv engine'            => array( 'CREATE TABLE `_sft_x` (`a` int) ENGINE=CSV' ),
			'create merge with union'           => array( 'CREATE TABLE `_sft_x` (`a` int) ENGINE=MERGE UNION=(`wp_users`)' ),
			'create with foreign key'           => array( 'CREATE TABLE `_sft_x` (`a` int, FOREIGN KEY (`a`) REFERENCES `wp_users` (`ID`))' ),
			'create like'                       => array( 'CREATE TABLE `_sft_x` LIKE `wp_users`' ),
			'non-temporary table'               => array( 'INSERT INTO `wp_users` (`a`) VALUES (1)' ),
			'unclosed quote'                    => array( "INSERT INTO `_sft_x` (`a`) VALUES ('abierta)" ),
			'other statement'                   => array( 'ALTER TABLE `_sft_x` RENAME TO `wp_users`' ),
			'comment hiding a select'           => array( "CREATE TABLE `_sft_x` (`a` int) ENGINE=InnoDB /*'*/ SELECT * FROM wp_users /*'*/" ),
			'executable comment'                => array( 'CREATE TABLE `_sft_x` (`a` int) /*!50000 SELECT * FROM wp_users */ ENGINE=InnoDB' ),
			'line comment'                      => array( "CREATE TABLE `_sft_x` (`a` int) ENGINE=InnoDB -- '\n SELECT 1" ),
			'hash comment'                      => array( "INSERT INTO `_sft_x` (`a`) VALUES (1) # '" ),
			'quoted engine'                     => array( "CREATE TABLE `_sft_x` (`a` int) ENGINE='FEDERATED' CONNECTION='mysql://u@h/d/t'" ),
			'engine without equals sign'        => array( 'CREATE TABLE `_sft_x` (`a` int) ENGINE FEDERATED' ),
			'two engines'                       => array( 'CREATE TABLE `_sft_x` (`a` int) ENGINE=InnoDB ENGINE=FEDERATED' ),
			'no engine'                         => array( 'CREATE TABLE `_sft_x` (`a` int)' ),
			'unknown option'                    => array( "CREATE TABLE `_sft_x` (`a` int) ENGINE=MyISAM INDEX DIRECTORY='/tmp'" ),
			'partitions'                        => array( 'CREATE TABLE `_sft_x` (`a` int) ENGINE=InnoDB PARTITION BY HASH (a)' ),
		);
	}

	/**
	 * @dataProvider legit
	 */
	public function test_accepts_what_the_copier_produces( string $sql ): void {
		$this->assertTrue( Schema::safe_temp_statement( $sql ) );
	}

	/**
	 * @dataProvider attacks
	 */
	public function test_rejects_attacks( string $sql ): void {
		$this->assertFalse( Schema::safe_temp_statement( $sql ) );
	}

	public function test_large_statements_do_not_exhaust_pcre(): void {
		$tuples = array_fill( 0, 20000, "(0x00103558C1855E3DAC1FB1A242E3CF56,'archivo.php',NULL,1,-2.5)" );
		$sql    = 'INSERT INTO `_sft_wp_wffilemods` (`a`,`b`,`c`,`d`,`e`) VALUES ' . implode( ',', $tuples );
		$this->assertTrue( Schema::safe_temp_statement( $sql ) );
		$this->assertFalse( Schema::safe_temp_statement( $sql . ',((SELECT 1))' ) );
	}

	public function test_pull_requires_the_exact_target_table(): void {
		$this->assertTrue( Schema::safe_temp_statement( 'DROP TABLE IF EXISTS `_sft_wp_posts`', '_sft_wp_posts' ) );
		$this->assertFalse( Schema::safe_temp_statement( 'DROP TABLE IF EXISTS `_sft_wp_users`', '_sft_wp_posts' ) );
		$this->assertFalse( Schema::safe_temp_statement( 'DROP TABLE IF EXISTS `wp_posts`', 'wp_posts' ), 'the target must be temporary' );
	}
}
