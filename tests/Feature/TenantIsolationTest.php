<?php

use App\Models\User;
use App\Modules\Tenancy\Models\Company;

test('a company member cannot view a company from another tenant', function () {
    $member = User::factory()->create();
    $memberCompany = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $member->companies()->attach($memberCompany, ['role' => 'operator', 'is_active' => true]);

    expect($member->can('view', $memberCompany))->toBeTrue();
    expect($member->can('view', $otherCompany))->toBeFalse();
});
