<?php

namespace Tests\Feature;

use App\Http\Requests\Api\V1\ShirtMilestoneEntryRequest;
use App\Http\Requests\Api\V1\ShirtMilestoneStoreRequest;
use App\Models\Employee;
use App\Models\EmployeeShirtMilestone;
use App\Models\ShirtColor;
use App\Models\ShirtLogo;
use App\Models\Store;
use App\Services\ShirtCatalogService;
use App\Services\ShirtMilestoneWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * The second-choice colour: required on every new entry, different from the
 * first, and kept on the milestone so fulfilment can fall back to it.
 */
class ShirtSecondaryColorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Submitting an entry records an outbox event; publishing it needs a
        // broker, which these tests have no use for.
        Queue::fake();
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    public function test_a_valid_second_choice_passes(): void
    {
        [$red, $navy, $logo] = $this->catalogue();

        $v = $this->validateEntry([
            'shirt_color_id' => $red->id,
            'secondary_shirt_color_id' => $navy->id,
            'shirt_logo_id' => $logo->id,
        ]);

        $this->assertFalse($v->fails(), json_encode($v->errors()->toArray()));
    }

    public function test_the_second_choice_is_required(): void
    {
        [$red, , $logo] = $this->catalogue();

        $v = $this->validateEntry([
            'shirt_color_id' => $red->id,
            'shirt_logo_id' => $logo->id,
        ]);

        $this->assertTrue($v->fails());
        $this->assertSame(
            'Pick a secondary colour, in case the first one is not available.',
            $v->errors()->first('secondary_shirt_color_id')
        );
    }

    public function test_the_second_choice_cannot_be_the_same_colour_as_the_first(): void
    {
        [$red, , $logo] = $this->catalogue();

        $v = $this->validateEntry([
            'shirt_color_id' => $red->id,
            'secondary_shirt_color_id' => $red->id,
            'shirt_logo_id' => $logo->id,
        ]);

        $this->assertTrue($v->fails());
        $this->assertSame(
            'The secondary colour must be different from the shirt colour.',
            $v->errors()->first('secondary_shirt_color_id')
        );
    }

    public function test_the_second_choice_must_be_an_active_colour(): void
    {
        [$red, $navy, $logo] = $this->catalogue();
        $navy->update(['is_active' => false]);

        $v = $this->validateEntry([
            'shirt_color_id' => $red->id,
            'secondary_shirt_color_id' => $navy->id,
            'shirt_logo_id' => $logo->id,
        ]);

        $this->assertTrue($v->fails());
        $this->assertSame(
            'The selected secondary colour is not available.',
            $v->errors()->first('secondary_shirt_color_id')
        );

        // A colour that does not exist at all is refused the same way.
        $unknown = $this->validateEntry([
            'shirt_color_id' => $red->id,
            'secondary_shirt_color_id' => 999999,
            'shirt_logo_id' => $logo->id,
        ]);

        $this->assertTrue($unknown->errors()->has('secondary_shirt_color_id'));
    }

    /**
     * Manual creation shares the entry rules, so it must ask for the second
     * choice too — otherwise "New Shirt Milestone" would be a way around it.
     */
    public function test_manual_creation_requires_the_second_choice_too(): void
    {
        [$red, , $logo] = $this->catalogue();

        $v = Validator::make(
            [
                'shirt_color_id' => $red->id,
                'shirt_logo_id' => $logo->id,
                'employee_id' => 1,
            ],
            (new ShirtMilestoneStoreRequest())->rules()
        );

        $this->assertTrue($v->errors()->has('secondary_shirt_color_id'));
    }

    // -------------------------------------------------------------------------
    // Persistence
    // -------------------------------------------------------------------------

    public function test_submitting_an_entry_saves_the_second_choice_and_returns_it(): void
    {
        [$red, $navy, $logo] = $this->catalogue();
        [$store, $milestone] = $this->makeMilestone();

        $result = app(ShirtMilestoneWorkflowService::class)->submitEntry($store, $milestone, [
            'shirt_color_id' => $red->id,
            'secondary_shirt_color_id' => $navy->id,
            'shirt_logo_id' => $logo->id,
            't_shirt_size' => 'L',
        ]);

        $this->assertSame($navy->id, $milestone->fresh()->secondary_shirt_color_id);

        // The queue and the detail sheet read the object, not just the id.
        $payload = $result->toArray();
        $this->assertSame('Navy', $payload['secondary_shirt_color']['name']);
        $this->assertSame($red->id, $payload['shirt_color']['id']);
    }

    public function test_manual_creation_saves_the_second_choice(): void
    {
        [$red, $navy, $logo] = $this->catalogue();
        [$store, $milestone] = $this->makeMilestone();

        $result = app(ShirtMilestoneWorkflowService::class)->createManual($store, [
            'employee_id' => $milestone->employee_id,
            'shirt_color_id' => $red->id,
            'secondary_shirt_color_id' => $navy->id,
            'shirt_logo_id' => $logo->id,
            't_shirt_size' => 'M',
        ]);

        $this->assertSame('manual', $result->source->value);
        $this->assertSame($navy->id, $result->secondary_shirt_color_id);
        $this->assertSame('Navy', $result->secondaryShirtColor->name);
    }

    /**
     * Milestones submitted before the column existed have no second choice.
     * They must still load and serialise, with the relation simply empty.
     */
    public function test_a_milestone_without_a_second_choice_still_loads(): void
    {
        [$red, , $logo] = $this->catalogue();
        [$store, $milestone] = $this->makeMilestone();

        app(ShirtMilestoneWorkflowService::class)->submitEntry($store, $milestone, [
            'shirt_color_id' => $red->id,
            'shirt_logo_id' => $logo->id,
            't_shirt_size' => 'L',
        ]);

        $payload = app(ShirtMilestoneWorkflowService::class)->load($milestone->fresh())->toArray();

        $this->assertNull($payload['secondary_shirt_color_id']);
        $this->assertNull($payload['secondary_shirt_color']);
    }

    /**
     * Retiring a colour from the catalogue only deactivates it, so a milestone
     * that already named it as the fallback keeps reading back correctly.
     */
    public function test_a_retired_colour_still_reads_back_as_the_second_choice(): void
    {
        [$red, $navy, $logo] = $this->catalogue();
        [$store, $milestone] = $this->makeMilestone();

        app(ShirtMilestoneWorkflowService::class)->submitEntry($store, $milestone, [
            'shirt_color_id' => $red->id,
            'secondary_shirt_color_id' => $navy->id,
            'shirt_logo_id' => $logo->id,
            't_shirt_size' => 'L',
        ]);

        app(ShirtCatalogService::class)->deactivateColor($navy);

        $payload = app(ShirtMilestoneWorkflowService::class)->load($milestone->fresh())->toArray();

        $this->assertSame('Navy', $payload['secondary_shirt_color']['name']);
        $this->assertFalse($payload['secondary_shirt_color']['is_active']);
    }

    // -------------------------------------------------------------------------

    /**
     * Rules AND messages, the way a real FormRequest applies them — rules()
     * alone would give Laravel's default wording instead of ours.
     */
    private function validateEntry(array $data): \Illuminate\Validation\Validator
    {
        $request = new ShirtMilestoneEntryRequest();

        return Validator::make($data, $request->rules(), $request->messages());
    }

    /** @return array{0: ShirtColor, 1: ShirtColor, 2: ShirtLogo} */
    private function catalogue(): array
    {
        $red = ShirtColor::query()->create(['name' => 'Red', 'hex_code' => '#C8102E']);
        $navy = ShirtColor::query()->create(['name' => 'Navy', 'hex_code' => '#1B2A4A']);
        $logo = ShirtLogo::query()->create([
            'name' => 'Primary',
            'file_path' => 'shirt-logos/x.svg',
            'mime_type' => 'image/svg+xml',
        ]);

        return [$red, $navy, $logo];
    }

    /** @return array{0: Store, 1: EmployeeShirtMilestone} */
    private function makeMilestone(): array
    {
        $store = Store::query()->firstOrCreate(['id' => 1], ['store_number' => '03759-00001']);

        $employee = Employee::query()->create([
            'first_name' => 'Dana',
            'last_name' => 'Rivera',
            'gender' => 'female',
            'ssn' => '000-00-0000',
            'employment_type' => 'W2',
        ]);

        DB::table('employee_stores')->insert([
            'employee_id' => $employee->id,
            'store_id' => $store->id,
            'effective_date' => '2026-01-15',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $milestone = EmployeeShirtMilestone::query()->create([
            'employee_id' => $employee->id,
            'store_id' => $store->id,
            'milestone_month' => 1,
            'stint_start_date' => '2026-01-15',
            'due_date' => '2026-02-15',
            'source' => 'automatic',
            'status' => 'pending_entry',
        ]);

        return [$store, $milestone];
    }
}
