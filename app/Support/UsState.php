<?php

namespace App\Support;

/**
 * Address state -> USPS postal code.
 *
 * Addresses used to be free text, so a state arrives as "Ohio", "ohio ",
 * "oh" or "OH". TCP accepts only the uppercase code and rejects anything else
 * outright ("The state must contain only uppercase letters"), failing the
 * whole create/update/status change. The employee requests normalise to the
 * code on the way in; TcpEmployeeMapper normalises again on the way out, for
 * rows saved before that.
 */
final class UsState
{
    private const CODES = [
        'ALABAMA' => 'AL', 'ALASKA' => 'AK', 'ARIZONA' => 'AZ', 'ARKANSAS' => 'AR',
        'CALIFORNIA' => 'CA', 'COLORADO' => 'CO', 'CONNECTICUT' => 'CT', 'DELAWARE' => 'DE',
        'DISTRICT OF COLUMBIA' => 'DC', 'FLORIDA' => 'FL', 'GEORGIA' => 'GA', 'HAWAII' => 'HI',
        'IDAHO' => 'ID', 'ILLINOIS' => 'IL', 'INDIANA' => 'IN', 'IOWA' => 'IA',
        'KANSAS' => 'KS', 'KENTUCKY' => 'KY', 'LOUISIANA' => 'LA', 'MAINE' => 'ME',
        'MARYLAND' => 'MD', 'MASSACHUSETTS' => 'MA', 'MICHIGAN' => 'MI', 'MINNESOTA' => 'MN',
        'MISSISSIPPI' => 'MS', 'MISSOURI' => 'MO', 'MONTANA' => 'MT', 'NEBRASKA' => 'NE',
        'NEVADA' => 'NV', 'NEW HAMPSHIRE' => 'NH', 'NEW JERSEY' => 'NJ', 'NEW MEXICO' => 'NM',
        'NEW YORK' => 'NY', 'NORTH CAROLINA' => 'NC', 'NORTH DAKOTA' => 'ND', 'OHIO' => 'OH',
        'OKLAHOMA' => 'OK', 'OREGON' => 'OR', 'PENNSYLVANIA' => 'PA', 'RHODE ISLAND' => 'RI',
        'SOUTH CAROLINA' => 'SC', 'SOUTH DAKOTA' => 'SD', 'TENNESSEE' => 'TN', 'TEXAS' => 'TX',
        'UTAH' => 'UT', 'VERMONT' => 'VT', 'VIRGINIA' => 'VA', 'WASHINGTON' => 'WA',
        'WEST VIRGINIA' => 'WV', 'WISCONSIN' => 'WI', 'WYOMING' => 'WY',
        'PUERTO RICO' => 'PR', 'GUAM' => 'GU', 'US VIRGIN ISLANDS' => 'VI',
        'AMERICAN SAMOA' => 'AS', 'NORTHERN MARIANA ISLANDS' => 'MP',
    ];

    /** @return list<string> every accepted postal code */
    public static function codes(): array
    {
        return array_values(self::CODES);
    }

    /** The postal code for a state name or code, or null if it isn't one. */
    public static function toCode(?string $state): ?string
    {
        // Collapse whitespace and drop dots, so "  new   york " and "N.Y." resolve.
        $key = strtoupper(trim(preg_replace('/\s+/', ' ', str_replace('.', '', (string) $state))));

        if ($key === '') {
            return null;
        }

        if (in_array($key, self::CODES, true)) {
            return $key;
        }

        return self::CODES[$key] ?? null;
    }
}
