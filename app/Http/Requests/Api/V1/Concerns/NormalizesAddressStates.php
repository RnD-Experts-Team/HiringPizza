<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Support\UsState;
use Illuminate\Validation\Rule;

/**
 * Stores every address state as its USPS code ("OH"). Anything recognisable
 * ("Ohio", "ohio", "oh") is converted before validation; anything else fails
 * here, on the field, instead of later as a TCP rejection that rolls the whole
 * save back with a message the user can't act on.
 */
trait NormalizesAddressStates
{
    protected function prepareForValidation(): void
    {
        $addresses = $this->input('addresses');

        if (!is_array($addresses)) {
            return;
        }

        foreach ($addresses as $i => $address) {
            if (is_array($address) && is_string($address['state'] ?? null)) {
                $addresses[$i]['state'] = UsState::toCode($address['state']) ?? trim($address['state']);
            }
        }

        $this->merge(['addresses' => $addresses]);
    }

    protected function stateRules(): array
    {
        return ['required', 'string', Rule::in(UsState::codes())];
    }

    public function messages(): array
    {
        return [
            'addresses.*.state.in' => 'The state must be a US state, e.g. OH or Ohio.',
        ];
    }
}
