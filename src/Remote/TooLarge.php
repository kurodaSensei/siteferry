<?php
/**
 * The other site's web server refused a request for its size.
 *
 * @package Siteferry
 */

namespace Siteferry\Remote;

/**
 * HTTP 413 from a web server or proxy in front of WordPress (nginx's client_max_body_size, for
 * instance), which PHP cannot report. The request never reached Siteferry, so it is safe to
 * send the same data again in smaller pieces.
 */
final class TooLarge extends \RuntimeException {
}
