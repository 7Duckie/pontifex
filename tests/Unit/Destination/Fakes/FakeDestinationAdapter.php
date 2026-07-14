<?php
/**
 * In-memory DestinationAdapter used by DestinationRetention unit tests.
 *
 * @package Pontifex\Tests\Unit\Destination\Fakes
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Destination\Fakes;

use Pontifex\Destination\DestinationAdapter;
use Pontifex\Destination\DestinationException;
use Pontifex\Destination\RemoteObject;

/**
 * In-memory implementation of {@see DestinationAdapter} for tests.
 *
 * Lets {@see \Pontifex\Destination\DestinationRetention} be exercised
 * without a network connection. Tests construct it with the remote names it
 * should report, optionally arrange for list() to throw, and optionally name
 * archives whose delete() should fail — so the best-effort swallow behaviour
 * is provable.
 */
final class FakeDestinationAdapter implements DestinationAdapter {

	/**
	 * The archives this fake reports from list().
	 *
	 * @var array<int, RemoteObject>
	 */
	private array $objects;

	/**
	 * Whether list() should throw a DestinationException.
	 *
	 * @var bool
	 */
	private bool $list_throws;

	/**
	 * Remote names whose delete() call should throw a DestinationException.
	 *
	 * @var array<int, string>
	 */
	private array $delete_failures;

	/**
	 * The remote names actually removed via a successful delete() call.
	 *
	 * @var array<int, string>
	 */
	private array $deleted_names = array();

	/**
	 * Construct a fake destination adapter.
	 *
	 * @param array<int, string> $names           The remote archive names list() should report.
	 * @param bool               $list_throws     Whether list() should throw a DestinationException.
	 * @param array<int, string> $delete_failures Remote names whose delete() call should throw.
	 */
	public function __construct( array $names, bool $list_throws = false, array $delete_failures = array() ) {
		$this->objects         = array_map(
			static fn ( string $name ): RemoteObject => new RemoteObject( $name, 100 ),
			$names
		);
		$this->list_throws     = $list_throws;
		$this->delete_failures = $delete_failures;
	}

	/**
	 * Not exercised by DestinationRetention; always refuses.
	 *
	 * @param string $local_path Unused.
	 * @return void
	 * @throws DestinationException Always.
	 */
	public function put( string $local_path ): void {
		throw new DestinationException( 'unused' );
	}

	/**
	 * Report the configured archives, or throw when configured to.
	 *
	 * @return array<int, RemoteObject>
	 * @throws DestinationException When constructed with $list_throws true.
	 */
	public function list(): array {
		if ( $this->list_throws ) {
			throw new DestinationException( 'unused' );
		}
		return $this->objects;
	}

	/**
	 * Not exercised by DestinationRetention; always refuses.
	 *
	 * @param string $remote_name Unused.
	 * @param string $local_path  Unused.
	 * @return void
	 * @throws DestinationException Always.
	 */
	public function get( string $remote_name, string $local_path ): void {
		throw new DestinationException( 'unused' );
	}

	/**
	 * Record a delete, or throw when the name was configured to fail.
	 *
	 * @param string $remote_name The remote basename to delete.
	 * @return void
	 * @throws DestinationException When $remote_name is in the configured failure list.
	 */
	public function delete( string $remote_name ): void {
		if ( in_array( $remote_name, $this->delete_failures, true ) ) {
			throw new DestinationException( 'unused' );
		}
		$this->deleted_names[] = $remote_name;
	}

	/**
	 * Not exercised by DestinationRetention; always refuses.
	 *
	 * @return void
	 * @throws DestinationException Always.
	 */
	public function test(): void {
		throw new DestinationException( 'unused' );
	}

	/**
	 * The remote names actually removed via a successful delete() call, in call order.
	 *
	 * @return array<int, string>
	 */
	public function deleted_names(): array {
		return $this->deleted_names;
	}
}
