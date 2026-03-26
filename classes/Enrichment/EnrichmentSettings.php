<?php
/**
 * EnrichmentSettings
 *
 * @package CustomCRM\Enrichment
 */

namespace CustomCRM\Enrichment;

/**
 * Reads and writes enrichment provider settings.
 */
class EnrichmentSettings {

	private const OPTION_KEY = 'custom_crm_enrichment_settings';

	/**
	 * Get all settings.
	 *
	 * @return array{active_provider:string,providers:array<string,array<string,mixed>>}
	 */
	public static function getAll(): array {
		$defaults = [
			'active_provider' => '',
			'providers'       => [],
		];

		$settings = get_option( self::OPTION_KEY, $defaults );

		return wp_parse_args( $settings, $defaults );
	}

	/**
	 * Save all settings.
	 *
	 * @param array<string,mixed> $settings Full settings array.
	 */
	public static function saveAll( array $settings ): void {
		update_option( self::OPTION_KEY, $settings, false );
	}

	/**
	 * Get settings for a specific provider.
	 *
	 * @param string $slug Provider slug.
	 *
	 * @return array<string,mixed>
	 */
	public static function getProviderSettings( string $slug ): array {
		$all = self::getAll();

		return $all['providers'][ $slug ] ?? [];
	}

	/**
	 * Save settings for a specific provider.
	 *
	 * @param string              $slug     Provider slug.
	 * @param array<string,mixed> $settings Provider-specific settings.
	 */
	public static function saveProviderSettings( string $slug, array $settings ): void {
		$all                       = self::getAll();
		$all['providers'][ $slug ] = $settings;

		// Auto-set active provider if none set.
		if ( empty( $all['active_provider'] ) ) {
			$all['active_provider'] = $slug;
		}

		self::saveAll( $all );
	}

	/**
	 * Get the active provider slug.
	 *
	 * @return string
	 */
	public static function getActiveProvider(): string {
		$all = self::getAll();

		return $all['active_provider'] ?? '';
	}

	/**
	 * Get the API key for a provider, decrypted.
	 *
	 * @param string $slug Provider slug.
	 *
	 * @return string API key or empty string.
	 */
	public static function getApiKey( string $slug ): string {
		$settings = self::getProviderSettings( $slug );

		$encrypted = $settings['api_key'] ?? '';

		if ( '' === $encrypted ) {
			return '';
		}

		return self::decrypt( $encrypted );
	}

	/**
	 * Encrypt a value for storage using OpenSSL with WordPress AUTH_KEY as the key.
	 *
	 * @param string $value Plain text value.
	 *
	 * @return string Encrypted string (base64-encoded with 'enc:' prefix).
	 */
	public static function encrypt( string $value ): string {
		$key = self::getEncryptionKey();

		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv        = openssl_random_pseudo_bytes( 16 );
			$encrypted = openssl_encrypt( $value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

			if ( false !== $encrypted ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				return 'enc:' . base64_encode( $iv . $encrypted );
			}
		}

		// Fallback if OpenSSL unavailable: base64 obfuscation only.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return 'b64:' . base64_encode( $value );
	}

	/**
	 * Decrypt a stored value.
	 *
	 * @param string $encrypted Encrypted string.
	 *
	 * @return string Decrypted value.
	 */
	public static function decrypt( string $encrypted ): string {
		if ( str_starts_with( $encrypted, 'enc:' ) && function_exists( 'openssl_decrypt' ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$raw  = base64_decode( substr( $encrypted, 4 ) );
			$iv   = substr( $raw, 0, 16 );
			$data = substr( $raw, 16 );
			$key  = self::getEncryptionKey();

			$decrypted = openssl_decrypt( $data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

			if ( false !== $decrypted ) {
				return $decrypted;
			}
		}

		if ( str_starts_with( $encrypted, 'b64:' ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			return base64_decode( substr( $encrypted, 4 ) );
		}

		// Plain text from old storage.
		return $encrypted;
	}

	/**
	 * Derive an encryption key from WordPress salts.
	 *
	 * @return string 32-byte key.
	 */
	private static function getEncryptionKey(): string {
		$salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'custom-crm-fallback-key';

		return hash( 'sha256', $salt, true );
	}
}
