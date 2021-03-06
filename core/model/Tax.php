<?php
/**
 * Tax.php
 * Tax manager
 *
 * @author Jonathan Davis
 * @copyright Ingenesis Limited, April 2013
 * @license GNU GPL version 3 (or later) {@see license.txt}
 * @package shopp
 * @version 1.0
 * @since 1.3
 **/

defined( 'WPINC' ) || header( 'HTTP/1.1 403' ) & exit; // Prevent direct access

/**
 * ShoppTax
 *
 * Manages order total registers
 *
 * @author Jonathan Davis
 * @since 1.3
 * @version 1.0
 * @package taxes
 **/
class ShoppTax {

	const ALL = '*';			// Wildcard for all locations
	const EUVAT = 'EUVAT';		// Special country value for European Union

	private $address = array(	// The address to apply taxes for
		'country' => false,
		'zone' => false,
		'locale' => false
	);

	private $Item = false;		// The ShoppTaxableItem to calculate taxes for
	private $Customer = false;	// The current ShoppCustomer to calculate taxes for

	/**
	 * Converts a provided item to a ShoppTaxableItem
	 *
	 * @author Jonathan Davis
	 * @since 1.3
	 *
	 * @param Object $Item An item object to convert to a ShoppTaxableItem
	 * @return void
	 **/
	public function item ( $Item ) {
		return new ShoppTaxableItem($Item);
	}

	/**
	 * Filters the tax settings based on
	 *
	 * @author Jonathan Davis
	 * @since 1.3
	 *
	 * @return array A list of tax rate settings
	 **/
	public function settings () {
		if ( ! shopp_setting_enabled('taxes') ) return false;

		$taxrates = shopp_setting('taxrates');

		$fallbacks = array();
		$settings = array();
		foreach ( $taxrates as $setting ) {

			$defaults = array(
				'rate' => 0,
				'country' => '',
				'zone' => '',
				'haslocals' => false,
				'logic' => 'any',
				'rules' => array(),
				'localrate' => 0,
				'compound' => false,
				'label' => Shopp::__('Tax')

			);
			$setting = array_merge($defaults, $setting);
			extract($setting);

			if ( ! $this->taxcountry($country) ) continue;
			if ( ! $this->taxzone($zone) ) continue;
			if ( ! $this->taxrules($rules, $logic) ) continue;

			// Capture fall back tax rates
			if ( empty($zone) && ( self::ALL == $country || self::EUVAT == $country) ) {
				$fallbacks[] = $setting;
				continue;
			}

			$key = hash('crc32b', serialize($setting)); // Key settings before local rates

			$setting['localrate'] = 0;
			if ( isset($setting['locals']) && is_array($setting['locals']) && isset($setting['locals'][ $this->address['locale'] ]) )
				$setting['localrate'] = $setting['locals'][ $this->address['locale'] ];

			$settings[ $key ] = $setting;
		}

		if ( empty($settings) && ! empty($fallbacks) )
			$settings = $fallbacks;

		$settings = apply_filters('shopp_cart_taxrate_settings', $settings); // @deprecated Use shopp_tax_rate_settings instead
		return apply_filters('shopp_tax_rate_settings', $settings);
	}

	/**
	 * Determines the applicable tax rates for a given taxable item
	 *
	 * @author Jonathan Davis
	 * @since 1.3
	 *
	 * @param Object $Item A taxable item object
	 * @return array The list of applicable tax rates as ShoppItemTax entries for a given item
	 **/
	public function rates ( array &$rates, ShoppTaxableItem $Item = null  ) {

		if ( isset($Item) ) $this->Item = $Item;

		$settings = $this->settings();

		foreach ( $settings as $key => $setting ) {

			// Add any local rate to the base rate, then divide by 100 to prepare the rate to be applied
			$rate = ( self::float($setting['rate']) + self::float($setting['localrate']) ) / 100;

			if ( ! isset($rates[ $key ]) ) $rates[ $key ] = new ShoppItemTax();
			$ShoppItemTax = $rates[ $key ];

			$ShoppItemTax->update(array(
				'label' => $setting['label'],
				'rate' => $rate,
				'amount' => 0.00,
				'total' => 0.00,
				'compound' => $setting['compound']
			));

		}

		// get list of existing rates that no longer match
		$unapply = array_keys(array_diff_key($rates, $settings));
		foreach ( $unapply as $key )
			$rates[ $key ]->amount = $rates[ $key ]->total = null;

		$rates = apply_filters( 'shopp_cart_taxrate', $rates ); // @deprecated Use shopp_tax_rates
		$rates = apply_filters( 'shopp_tax_rates', $rates );

	}

	/**
	 * Evaluates if the given country matches the taxable address
	 *
	 * @author Jonathan Davis
	 * @since 1.3
	 *
	 * @param string $country The country code
	 * @return boolean True if the country matches or false
	 **/
	protected function taxcountry ( string $country ) {
		if ( empty($country) ) return false;
		return apply_filters('shopp_tax_country', ( $this->address['country'] == $country || self::ALL == $country ),  $this->address['country'], $country);
	}

	/**
	 * Evaluates if the given zone (state/province) matches the taxable address
	 *
	 * @author Jonathan Davis
	 * @since 1.3
	 *
	 * @param string $zone The name of the zone
	 * @return boolean True if the zone matches or false
	 **/
	protected function taxzone ( string $zone ) {
		if ( empty($zone) ) return true;
		return ($this->address['zone'] == $zone);
	}

	/**
	 * Evaluates the tax rules against the taxable Item or ShoppCustomer
	 *
	 * @author Jonathan Davis
	 * @since 1.3
	 *
	 * @param array $rules The list of tax rules to test
	 * @return boolean True if the rules match enough to apply, false otherwise
	 **/
	protected function taxrules ( array $rules, string $logic ) {
		if ( empty($rules) ) return true;

		$apply = false;
		$matches = 0;

		foreach ($rules as $rule) {
			$match = false;

			if ( false !== $this->Item && false !== strpos($rule['p'],'product') ) {
				$match = $this->Item->taxrule($rule);
			} elseif ( false !== strpos($rule['p'],'customer')) {
				$match = $this->Customer->taxrule($rule);
			}

			if ($match) $matches++;
		}
		if ( 'any' == $logic && $matches > 0) $apply = true;
		if ( 'all' == $logic && count($rules) == $matches ) $apply = true;

		return apply_filters('shopp_tax_rate_match_rule',$apply,$rule,$this);
	}


	/**
	 * Sets the taxable address for applying the proper tax rates
	 *
	 * @author Jonathan Davis
	 * @since 1.3
	 *
	 * @param BillingAddress $Billing The billing address
	 * @param ShippingAddress $Shipping The shipping address
	 * @param boolean $shipped Flag if the order is shippable
	 * @return array An associative array containing the country, zone and locale
	 **/
	public function address ( BillingAddress $Billing, ShippingAddress $Shipping = null, $shipped = false ) {

		$Address = $Billing;
		if ( $shipped && null !== $Shipping || shopp_setting_enabled('tax_destination') ) // @todo add setting for "Apply tax to the shipping address"
			$Address = $Shipping;

		$country = $Address->country;
		$zone = $Address->state;
		$locale = false;

		// Locale is always tracked with the billing address even though it is may be a shipping locale
		if ( isset($Billing->locale) ) $locale = $Billing->locale;

		$this->address = array_merge(apply_filters('shopp_taxable_address', compact('country','zone','locale')));

		return $this->address;
	}

	/**
	 * Formats tax rates to a precision beyond the currency format
	 *
	 * @author Jonathan Davis
	 * @since 1.3
	 *
	 * @param scalar $amount An amount to convert to a float
	 * @return float The float amount
	 **/
	private static function float ( $amount ) {
		$base = shopp_setting('base_operations');
		$format = $base['currency']['format'];
		$format['precision'] = 6;
		return Shopp::floatval($amount, true, $format);
	}

	/**
	 * Calculate taxes for a taxable amount using the given tax rates
	 *
	 * @author Jonathan Davis
	 * @since 1.3
	 *
	 * @param array $rates A list of ShoppItemTax objects
	 * @param float $taxable The amount to calculate taxes on
	 * @return float The total tax amount
	 **/
	public static function calculate ( array &$rates, float $taxable ) {

		$compound = 0;
		$total = 0;
		$inclusive = shopp_setting_enabled('tax_inclusive');
		foreach ( $rates as $label => $taxrate ) {

			if ( is_null($taxrate->total) ) continue; 		// Skip taxes flagged to be removed from the item

			$taxrate->amount = 0; // Reset the captured tax amount @see Issue #2430

			// Calculate tax amount
			if ( $inclusive ) $tax = $taxable - ( $taxable / (1 + $taxrate->rate) );
			else $tax = $taxable * $taxrate->rate;

			if ( $taxrate->compound ) {

				if ( 0 == $compound ) $compound = $taxable;	// Set initial compound taxable amount
				else {
					if ( $inclusive ) $tax = $compound - ( $compound / (1 + $taxrate->rate) );
					else $tax = ($compound * $taxrate->rate);	// Compounded tax amount
				}

				$compound += $tax;						 	// Set compound taxable amount for next compound rate
			}

			$taxrate->amount = $tax;						// Capture the tax amount calculate for this taxrate
			$total += $tax;									// Sum all of the taxes to get the total tax for the item

		}

		return $total;

	}

	/**
	 * Provides the tax exclusive amount of a given tax inclusive amount
	 *
	 * @author Jonathan Davis
	 * @since 1.3
	 *
	 * @param array $rates A list of ShoppItemTax objects
	 * @param float $$amount The amount including tax
	 * @return float The amount excluding tax
	 **/
	public static function exclusive ( array &$rates, float $amount ) {
		$taxrate = 0;
		foreach ( $rates as $tax )
			$taxrate += $tax->rate;
		return (float) $amount / (1 + $taxrate);
	}

	/**
	 * Calculates the total tax amount factored by quantity for the given tax rates
	 *
	 * @author Jonathan Davis
	 * @since 1.3
	 *
	 * @param int $quantity The quantity to factor tax amounts by
	 * @param array $rates the list of applicable ShoppItemTax entries
	 * @return float $total
	 **/
	public function total ( array &$taxes, integer $quantity ) {

		$total = 0;
		foreach ( $taxes as $label => &$taxrate ) {
			$taxrate->total = $taxrate->amount * $quantity;
			$total += $taxrate->total;
		}

		return (float)$total;

	}

	public static function euvat ( $result, $country, $setting ) {
		if ( self::EUVAT != $setting ) return $result; // Passthru
		return in_array($country, Lookup::country_euvat());
	}

	public function __sleep () {
		return array('address');
	}

}

/**
 * Adapter class that translates other product/item classes to a ShoppTax compatible object
 *
 * @author Jonathan Davis
 * @since 1.3
 * @package taxes
 **/
class ShoppTaxableItem {

	private $class;
	private $Object;

	function __construct ( $Object ) {

		$this->Object = $Object;
		$this->class = get_class($Object);

	}

	/**
	 * Routes the tax rule comparison to the proper object class handler
	 *
	 * @author Jonathan Davis
	 * @since 1.3
	 *
	 * @return boolean True if the rule matches the value
	 **/
	public function taxrule ( $rule ) {

		$property = $rule['p'];
		$value = $rule['v'];

		if ( method_exists($this, $this->class) )
			return call_user_func(array($this, $this->class), $property, $value);

		return false;
	}

	/**
	 * Evaluates tax rules for ShoppCartItem objects
	 *
	 * @author Jonathan Davis
	 * @since 1.3
	 *
	 * @param string $property The property to test
	 * @param string $value The value to match
	 * @return boolean True if matched or false
	 **/
	private function ShoppCartItem ( string $property, string $value ) {
		$CartItem = $this->Object;
		switch ( $property ) {
			case 'product-name': return ($value == $CartItem->name); break;
			case 'product-tags': return in_array($value, $CartItem->tags); break;
			case 'product-category': return in_array($value, $CartItem->categories); break;
		}
		return false;
	}

	/**
	 * Evaluates tax rules for Product objects
	 *
	 * @author Jonathan Davis
	 * @since 1.3
	 *
	 * @param string $property The property to test
	 * @param string $value The value to match
	 * @return boolean True if matched or false
	 **/
	private function ShoppProduct ( string $property, string $value ) {
		$Product = $this->Object;
		switch ( $property ) {
			case 'product-name': return ($value == $Product->name); break;
			case 'product-tags':
				if ( empty($Product->tags) ) $Product->load_data( array('tags') );
				foreach ($Product->tags as $tag) if ($value == $tag->name) return true;
				break;
			case 'product-category':
				if ( empty($Product->categories) ) $Product->load_data( array('categories') );
				foreach ($Product->categories as $category) if ($value == $category->name) return true;
		}
		return false;
	}

	/**
	 * Evaluates tax rules for ShoppPurchased objects
	 *
	 * @author Jonathan Davis
	 * @since 1.3
	 *
	 * @param string $property The property to test
	 * @param string $value The value to match
	 * @return boolean True if matched or false
	 **/
	private function purchased () {
		// @todo Complete ShoppPurchased tax rule match for ShoppTaxableItem
	}

}

/**
 * Defines a ShoppItemTax object
 *
 * @author Jonathan Davis
 * @since 1.3
 * @package taxes
 **/
class ShoppItemTax extends AutoObjectFramework {

	public $label = '';
	public $rate = 0.00;
	public $amount = 0.00;
	public $total = 0.00;
	public $compound = false;

}

/**
 * Storage class for taxes applied to a purchase and saved in a ShoppPurchase record
 *
 * @author Jonathan Davis
 * @since 1.3
 * @package shopp
 **/
class ShoppPurchaseTax {

	public $id = false;						// The originating Tax object id
	public $name = '';						// The name of the Tax
	public $rate = 0.00;					// Tax rate
	public $amount = 0.00;					// The total amount of taxes

	public function __construct ( OrderAmountTax $Tax ) {

		$this->id = $Tax->id();
		$this->name = $Tax->label();
		$this->rate = $Tax->rate();
		$this->amount = $Tax->amount();

	}

}