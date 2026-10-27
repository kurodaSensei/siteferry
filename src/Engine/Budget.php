<?php
/**
 * Presupuesto de tiempo de un paso.
 *
 * @package Siteferry
 */

namespace Siteferry\Engine;

/**
 * Cuánto puede trabajar una petición antes de devolver el control: tiempo y, opcionalmente,
 * bytes producidos (para no pasar del tamaño de una petición o respuesta remota).
 */
final class Budget {

	/**
	 * Momento límite (microtime).
	 *
	 * @var float
	 */
	private float $deadline;

	/**
	 * Constructor.
	 *
	 * @param float $seconds Segundos disponibles (0 = una unidad de trabajo por llamada).
	 */
	public function __construct( float $seconds ) {
		$this->deadline = microtime( true ) + $seconds;
	}

	/**
	 * Tope de bytes (0 = sin tope).
	 *
	 * @var int
	 */
	private int $max_bytes = 0;

	/**
	 * Bytes producidos.
	 *
	 * @var int
	 */
	private int $spent = 0;

	/**
	 * Mismo plazo, con un tope de bytes.
	 *
	 * @param int $bytes Tope.
	 */
	public function limited( int $bytes ): self {
		$copy            = clone $this;
		$copy->max_bytes = $bytes;
		$copy->spent     = 0;
		return $copy;
	}

	/**
	 * Registra bytes producidos.
	 *
	 * @param int $bytes Bytes.
	 */
	public function spend( int $bytes ): void {
		$this->spent += $bytes;
	}

	/**
	 * Si ya se acabó el tiempo o se llegó al tope de bytes.
	 */
	public function exhausted(): bool {
		return microtime( true ) >= $this->deadline || ( $this->max_bytes && $this->spent >= $this->max_bytes );
	}
}
