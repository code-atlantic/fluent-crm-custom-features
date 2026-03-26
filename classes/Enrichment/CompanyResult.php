<?php
/**
 * CompanyResult DTO
 *
 * @package CustomCRM\Enrichment
 */

namespace CustomCRM\Enrichment;

/**
 * Normalized company enrichment result from any provider.
 *
 * All fields are nullable — providers populate what they can.
 */
class CompanyResult {

	// --- Native Company fields ---
	public ?string $name             = null;
	public ?string $industry         = null;
	public ?string $type             = null;
	public ?string $website          = null;
	public ?string $email            = null;
	public ?string $phone            = null;
	public ?string $address_line_1   = null;
	public ?string $city             = null;
	public ?string $state            = null;
	public ?string $country          = null;
	public ?string $postal_code      = null;
	public ?int    $employees_number = null;
	public ?string $description      = null;
	public ?string $logo             = null;
	public ?string $linkedin_url     = null;
	public ?string $facebook_url     = null;
	public ?string $twitter_url      = null;
	public ?string $date_of_start    = null;

	// --- Extra metadata (stored in Company meta JSON) ---
	public ?int    $funding_raised              = null;
	public ?string $funding_stage               = null;
	public ?string $inferred_revenue            = null;
	public ?float  $employee_growth_rate_12mo   = null;
	public ?string $ticker                      = null;
	public ?array  $naics_codes                 = null;

	// --- Enrichment metadata ---
	public ?int $likelihood = null;

	/**
	 * Return fields that map to native Company columns.
	 *
	 * @return array<string,mixed> Only non-null values.
	 */
	public function toCompanyFields(): array {
		$map = [
			'name'             => $this->name,
			'industry'         => $this->industry,
			'type'             => $this->type,
			'website'          => $this->website,
			'email'            => $this->email,
			'phone'            => $this->phone,
			'address_line_1'   => $this->address_line_1,
			'city'             => $this->city,
			'state'            => $this->state,
			'country'          => $this->country,
			'postal_code'      => $this->postal_code,
			'employees_number' => $this->employees_number,
			'description'      => $this->description,
			'logo'             => $this->logo,
			'linkedin_url'     => $this->linkedin_url,
			'facebook_url'     => $this->facebook_url,
			'twitter_url'      => $this->twitter_url,
			'date_of_start'    => $this->date_of_start,
		];

		return array_filter( $map, static fn( $v ) => null !== $v );
	}

	/**
	 * Return extra fields for the Company meta JSON column.
	 *
	 * @return array<string,mixed> Only non-null values.
	 */
	public function toCompanyMeta(): array {
		$map = [
			'funding_raised'            => $this->funding_raised,
			'funding_stage'             => $this->funding_stage,
			'inferred_revenue'          => $this->inferred_revenue,
			'employee_growth_rate_12mo' => $this->employee_growth_rate_12mo,
			'ticker'                    => $this->ticker,
			'naics_codes'               => $this->naics_codes,
		];

		return array_filter( $map, static fn( $v ) => null !== $v );
	}
}
