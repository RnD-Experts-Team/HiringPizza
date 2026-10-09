<?php

namespace Tests\Feature;

use App\Http\Requests\Api\V1\EmployeeWorkflowStoreRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TCP rejects any state that isn't an uppercase postal code, and that
 * rejection rolls the whole save back. These pin the request-side guard:
 * recognisable input is converted, anything else is a field error.
 */
class EmployeeAddressStateValidationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('recognisableStates')]
    public function test_a_recognisable_state_is_stored_as_its_code(string $input): void
    {
        $validated = $this->validate($input);

        $this->assertSame('OH', $validated['addresses'][0]['state']);
    }

    public static function recognisableStates(): array
    {
        return [
            'full name, trailing space' => ['Ohio '],
            'lowercase name' => ['ohio'],
            'lowercase code' => ['oh'],
            'code' => ['OH'],
        ];
    }

    public function test_an_unrecognisable_state_is_a_field_error(): void
    {
        try {
            $this->validate('Ohiooo');
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('addresses.0.state', $e->errors());
            $this->assertStringContainsString('US state', $e->errors()['addresses.0.state'][0]);
        }
    }

    private function validate(string $state): array
    {
        $request = EmployeeWorkflowStoreRequest::createFrom(Request::create('/', 'POST', [
            'first_name' => 'Marco',
            'last_name' => 'Rossi',
            'gender' => 'male',
            'ssn' => '123-45-6789',
            'employment_type' => 'W2',
            'addresses' => [
                ['address_name' => 'home', 'address_1' => '1 Main St', 'city' => 'Cincinnati', 'state' => $state, 'zip_code' => '45225', 'is_primary' => true],
            ],
        ]));

        $request->setContainer($this->app)->setRedirector($this->app->make(Redirector::class));
        $request->validateResolved();

        return $request->validated();
    }
}
