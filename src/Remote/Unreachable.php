<?php
/**
 * The other site could not be reached.
 *
 * @package Siteferry
 */

namespace Siteferry\Remote;

/**
 * A network failure, timeout or server error after the client's own retries. Nothing was
 * decided on either side, so the job pauses and can be resumed where it stopped.
 */
final class Unreachable extends \RuntimeException {
}
