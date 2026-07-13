<?php
/**
 * Pontifex scan-progress manifest builder — attaches a progress callback to every build.
 *
 * @package Pontifex\Manifest
 */

declare(strict_types=1);

namespace Pontifex\Manifest;

/**
 * Decorates a manifest builder so every build reports scan progress.
 *
 * The resumable export runner rebuilds the manifest itself on every tick and
 * calls {@see ManifestBuilderInterface::build()} without a progress callback —
 * it has no surface to report to. The admin Backup screen does: its scanning
 * phase shows a climbing file count, and its cancel control is honoured from
 * inside the scan callback. This decorator carries that callback into builds
 * the caller does not initiate directly, so handing the scan to the runner
 * costs neither the live count nor mid-scan cancellation.
 */
final class ScanProgressManifestBuilder implements ManifestBuilderInterface {

	/**
	 * The builder doing the real work.
	 *
	 * @var ManifestBuilderInterface
	 */
	private ManifestBuilderInterface $inner;

	/**
	 * The scan-progress callback attached to every build.
	 *
	 * @var callable(int): void
	 */
	private $on_scan_progress;

	/**
	 * Construct the decorator.
	 *
	 * @param ManifestBuilderInterface $inner            The builder doing the real work.
	 * @param callable                 $on_scan_progress Called with the climbing scanned-file count.
	 */
	public function __construct( ManifestBuilderInterface $inner, callable $on_scan_progress ) {
		$this->inner            = $inner;
		$this->on_scan_progress = $on_scan_progress;
	}

	/**
	 * Build the manifest, reporting scan progress to the attached callback.
	 *
	 * A callback passed by the caller wins; the attached one is the default
	 * for callers (the resumable runner) that pass none.
	 *
	 * @param string        $wordpress_root   Absolute path the scan starts from.
	 * @param callable|null $on_scan_progress Optional caller-supplied progress callback.
	 * @return ManifestStream The built stream.
	 */
	public function build( string $wordpress_root, ?callable $on_scan_progress = null ): ManifestStream {
		return $this->inner->build( $wordpress_root, $on_scan_progress ?? $this->on_scan_progress );
	}
}
