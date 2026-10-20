<?php

namespace MediaWiki\Extension\Wanda;

use BagOStuff;
use RuntimeException;

/**
 * Short-lived, wiki/owner-bound batches and proposals in the main object stash.
 * Use a shared, atomic-add-capable stash (not a process-local/WAN cache).
 * Eviction is a hard failure, never permission to regenerate or repeat a save.
 */
class WandaEditStore {

	/** @var BagOStuff */
	private $cache;
	/** @var string */
	private $wiki;
	/** @var int */
	private $ttl;
	/** @var array */
	private $leases = [];

	public function __construct( BagOStuff $cache, string $wiki, int $ttl = 1800 ) {
		$this->cache = $cache;
		$this->wiki = $wiki;
		// BagOStuff treats values above 30 days as absolute timestamps.
		$this->ttl = max( 1, min( $ttl, 86400 ) );
	}

	/** @return string Opaque 256-bit ID; no content or identity in public IDs. */
	public function create( string $kind, string $owner, array $data ): string {
		$id = bin2hex( random_bytes( 32 ) );
		$key = $this->key( $kind, $id, $owner );
		$record = [ 'expires' => $this->now() + $this->ttl, 'data' => $data ];
		if ( !$this->cache->add( $key, $record, $this->ttl ) || !$this->persisted( $key, $record ) ) {
			throw new RuntimeException( 'wanda-edit-store-failed' );
		}
		return $id;
	}

	public function get( string $kind, string $id, string $owner ): array {
		return $this->record( $this->key( $kind, $id, $owner ) )['data'];
	}

	/**
	 * A lease lasts beyond the record's entire remaining lifetime. It cannot expire
	 * and be stolen while an old request still writes. A crashed worker therefore
	 * blocks this record until expiry: availability is sacrificed for safety.
	 * Normal exceptions release the lease and retain any saved in-flight marker.
	 * @return mixed Callback result
	 */
	public function withLock( string $kind, string $id, string $owner, callable $callback ) {
		$key = $this->key( $kind, $id, $owner );
		$record = $this->record( $key );
		$token = bin2hex( random_bytes( 16 ) );
		$lock = $key . ':lock';
		if ( !$this->cache->add( $lock, $token, $record['expires'] - $this->now() + 60 ) ) {
			throw new RuntimeException( 'wanda-edit-busy' );
		}
		$this->leases[$key] = $token;
		try {
			$this->assertLease( $key );
			return $callback( $this->record( $key )['data'] );
		} finally {
			unset( $this->leases[$key] );
			if ( $this->cache->get( $lock, BagOStuff::READ_LATEST ) === $token ) {
				$this->cache->delete( $lock );
			}
		}
	}

	/** Update only while holding the lease, without extending the original TTL. */
	public function update( string $kind, string $id, string $owner, array $data ): void {
		$key = $this->key( $kind, $id, $owner );
		$this->assertLease( $key );
		$record = $this->record( $key );
		$record['data'] = $data;
		if ( !$this->cache->set( $key, $record, max( 1, $record['expires'] - $this->now() ) )
			|| !$this->persisted( $key, $record )
		) {
			throw new RuntimeException( 'wanda-edit-store-failed' );
		}
	}

	private function assertLease( string $key ): void {
		if ( !isset( $this->leases[$key] )
			|| $this->cache->get( $key . ':lock', BagOStuff::READ_LATEST ) !== $this->leases[$key]
		) {
			throw new RuntimeException( 'wanda-edit-store-failed' );
		}
	}

	private function key( string $kind, string $id, string $owner ): string {
		if ( !in_array( $kind, [ 'batch', 'proposal' ], true )
			|| !preg_match( '/\A[0-9a-f]{64}\z/', $id ) || $owner === ''
		) {
			throw new RuntimeException( 'wanda-edit-invalid-record' );
		}
		return $this->cache->makeGlobalKey( 'wanda-edit', 'v1', hash( 'sha256', $this->wiki ),
			hash( 'sha256', $owner ), $kind, $id );
	}

	private function record( string $key ): array {
		$record = $this->cache->get( $key, BagOStuff::READ_LATEST );
		if ( !is_array( $record ) || !is_int( $record['expires'] ?? null )
			|| $record['expires'] <= $this->now() || !is_array( $record['data'] ?? null )
		) {
			// Same response for an expired, foreign, malformed or missing record.
			throw new RuntimeException( 'wanda-edit-invalid-record' );
		}
		return $record;
	}

	private function persisted( string $key, array $record ): bool {
		return $this->cache->get( $key, BagOStuff::READ_LATEST ) === $record;
	}

	/** Clock seam for deterministic expiry tests. */
	protected function now(): int {
		return time();
	}
}
