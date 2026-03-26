<?php
/**
 * EnrichmentError DTO
 *
 * @package CustomCRM\Enrichment
 */

namespace CustomCRM\Enrichment;

/**
 * Normalized error returned by enrichment providers.
 */
class EnrichmentError {

	public const NO_MATCH       = 'no_match';
	public const INVALID_INPUT  = 'invalid_input';
	public const AUTH_FAILED    = 'auth_failed';
	public const RATE_LIMITED   = 'rate_limited';
	public const QUOTA_EXCEEDED = 'quota_exceeded';
	public const PROVIDER_ERROR = 'provider_error';
	public const NETWORK_ERROR  = 'network_error';

	/** @var string Normalized error code (use class constants). */
	public string $code;

	/** @var string Human-readable error message. */
	public string $message;

	/** @var int|null Raw HTTP status from the provider. */
	public ?int $http_status;

	/** @var bool Whether the caller should retry. */
	public bool $retryable;

	/**
	 * @param string   $code        One of the class constants.
	 * @param string   $message     Human-readable message.
	 * @param int|null $http_status Raw HTTP status code.
	 * @param bool     $retryable   Whether the error is retryable.
	 */
	public function __construct( string $code, string $message, ?int $http_status = null, bool $retryable = false ) {
		$this->code        = $code;
		$this->message     = $message;
		$this->http_status = $http_status;
		$this->retryable   = $retryable;
	}
}
