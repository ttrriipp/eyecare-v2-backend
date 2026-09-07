<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Critical regression suite
|--------------------------------------------------------------------------
|
| Keep this bounded set for fast day-to-day verification. The default test
| command still runs the complete suite, including tests outside this group.
|
*/
pest()->group('critical')->in(
    'Feature/Auth/IssueOtpChallengeTest.php',
    'Feature/Auth/VerifyOtpChallengeTest.php',
    'Feature/Auth/DeliverOtpChallengeTest.php',
    'Feature/Security/RoleAssignmentTest.php',
    'Feature/Api/V1/PatientRegistrationTest.php',
    'Feature/Api/V1/PatientLoginTest.php',
    'Feature/Api/V1/PatientContactTest.php',
    'Feature/Api/V1/PatientPasswordRecoveryTest.php',
    'Feature/Api/V1/SubmitAppointmentRequestTest.php',
    'Feature/Api/V1/AppointmentRequestOwnershipTest.php',
    'Feature/Appointments/ReviewAppointmentRequestTest.php',
    'Feature/Appointments/SchedulingCharacterizationTest.php',
    'Feature/Encounters/EncounterLifecycleCharacterizationTest.php',
    'Feature/Encounters/CompleteEncounterTest.php',
    'Feature/Encounters/EncounterCheckInTest.php',
    'Feature/Encounters/EncounterClinicalFieldsTest.php',
    'Feature/BillingRecords/PaymentLifecycleTest.php',
    'Feature/BillingRecords/PaymentAndDispensingCharacterizationTest.php',
    'Feature/Quotations/CreateQuotationTest.php',
    'Feature/Quotations/ValidateOpticalQuotationTest.php',
    'Feature/OpticalOrders/AcceptAndStartOpticalOrderTest.php',
    'Feature/ProductCatalogTaxonomyTest.php',
    'Feature/Filament/AppointmentRequestResourceTest.php',
);

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/
