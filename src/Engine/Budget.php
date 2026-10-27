<?php
/**
 * Time budget for a step.
 *
 * @package Siteferry
 */

namespace Siteferry\Engine;

/**
 * How long a request may work before handing control back: time and, optionally,
 * bytes produced (so as not to exceed the size of a remote request or response).
 */
final class Budget {

	/**
	 * Deadline (microtime).
	 *
	 * @var float
	 */
	private float $deadline;

	/**
	 * Constructor.
	 *
	 * @param float $seconds Seconds available (0 = one unit of work per call).
	 */
	public function __construct( float $seconds ) {
		$this->deadline = microtime( true ) + $seconds;
	}

	/**
	 * Byte cap (0 = no cap).
	 *
	 * @var int
	 */
	private int $max_bytes = 0;

	/**
	 * Bytes produced.
	 *
	 * @var int
	 */
	private int $spent = 0;

	/**
	 * Same deadline, with a byte cap.
	 *
	 * @param int $bytes Cap.
	 */
	public function limited( int $bytes ): self {
		$copy            = clone $this;
		$copy->max_bytes = $bytes;
		$copy->spent     = 0;
		return $copy;
	}

	/**
	 * Records bytes produced.
	 *
	 * @param int $bytes Bytes.
	 */
	public function spend( int $bytes ): void {
		$this->spent += $bytes;
	}

	/**
	 * Whether time has run out or the byte cap has been reached.
	 */
	public function exhausted(): bool {
		return microtime( true ) >= $this->deadline || ( $this->max_bytes && $this->spent >= $this->max_bytes );
	}
}
