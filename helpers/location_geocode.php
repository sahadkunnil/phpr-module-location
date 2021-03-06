<?php

// For functional details see ../vendor/geocoder/README.md

class Location_Geocode
{
	protected static $initialized = false;
	protected static $adapter;
	protected static $geocoder;

	public static function init_geocode()
	{
		if (self::$initialized)
			return;
			
		require_once(PATH_APP."/modules/location/vendor/geocoder/Autoloader.php");

		self::$adapter  = new HttpAdapter_Curl();
		self::$geocoder = new Geocoder();
		self::$initialized = true;
	}

	public static function from_address($address=null)
	{
		self::init_geocode();

		// Determine default address lookup provider
		$config = Location_Config::create();
		$address_provider_class_name = ($config->address_lookup_provider) 
			? $config->address_lookup_provider
			: 'Provider_GoogleMaps';

		$provider = new $address_provider_class_name(self::$adapter);

		self::$geocoder->registerProvider($provider);
		$result = self::$geocoder->geocode($address);

		Phpr::$events->fire_event('location:after_geocode_from_address', $result);

		return $result;
	}

	public static function from_ip($ip=null)
	{
		self::init_geocode();
		
		if ($ip===null)
			$ip = $_SERVER['REMOTE_ADDR'];

		// Determine default IP lookup provider
		$config = Location_Config::create();
		$ip_provider_class_name = ($config->ip_lookup_provider) 
			? $config->ip_lookup_provider
			: 'Provider_FreeGeoIp';

		$provider = new $ip_provider_class_name(self::$adapter);

		self::$geocoder->registerProvider($provider);
		return self::$geocoder->geocode($ip);
	}

	// Attempts to find an address from a string and apply it to an object
	public static function address_to_object($model, $address_string)
	{
		$geocode = self::from_address($address_string);
		if ($geocode->getZipcode() && $geocode->getCountryCode())
		{
			$model->street_addr = $geocode->getStreetNumber() . " " . $geocode->getStreetName();
			$model->zip = $geocode->getZipcode();
			$model->city = $geocode->getCity();

			if ($state = Location_State::create()->find_by_name($geocode->getRegion()))
				$model->state_id = $state->id;

			if ($country = Location_Country::create()->find_by_code($geocode->getCountryCode()))
				$model->country_id = $country->id;
		}

		if ($geocode->getLatitude() && $geocode->getLongitude())
		{
			$model->latitude = $geocode->getLatitude();
			$model->longitude = $geocode->getLongitude();
		}

		return $model;
	}

	public static function geocode_to_object($model, $address_string) 
	{
		$geocode = self::from_address($address_string);
		if ($geocode->getLatitude() && $geocode->getLongitude())
		{
			$model->latitude = $geocode->getLatitude();
			$model->longitude = $geocode->getLongitude();
		}
		return $model;
	}

	public static function address_to_array($array, $address_string)
	{
		$tmp_object = new stdClass();
		$tmp_object = self::address_to_object($tmp_object, $address_string);
		$tmp_array = (array)$tmp_object;
		$array = array_merge($array, $tmp_array);
		return $array;
	}

	/**
	 * Returns all models within a circular radius of a given point (lat/lng)
	 * $model - Db\ActiveRecord
	 * $lat - Given point latitude
	 * $lng - Given point longitude
	 * $radius - search area radius
	 * $type - unit of measurement for radius
	 * Model must have columns 'latitude' and 'longitude'
	 */
	public static function apply_search_area($model, $lat, $lng, $radius=100, $type='mi')
	{
		// Maximum 1000, self imposed limit
		if (!floatval($radius) || floatval($radius) > 1000)
			$radius = 1000;

		$unit = ($type == 'km') 
			? 6371 // kms
			: 3959; // miles

		$bind = array(
			'unit' => $unit,
			'lat' => $lat,
			'lng' => $lng,
			'radius' => $radius
		);

		$model->where("( :unit * acos( 
				cos( radians( :lat ) ) 
				* cos( radians( latitude ) ) 
				* cos( radians( longitude ) - radians( :lng ) ) 
				+ sin( radians( :lat ) ) 
				* sin( radians( latitude ) ) 
			) ) < :radius", $bind);
		return $model;
	}
}
