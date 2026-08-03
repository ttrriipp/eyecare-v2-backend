<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\BeginPatientLogin;
use App\Actions\Auth\DispatchOtpChallenge;
use App\Actions\Auth\IssueOtpChallenge;
use App\Actions\Auth\IssuePatientDeviceToken;
use App\Actions\Auth\RecoverPatientPassword;
use App\Actions\Auth\RegisterPatientAccount;
use App\Actions\Auth\VerifyOtpChallenge;
use App\Actions\Auth\VerifyStepUpOtp;
use App\Actions\PatientAccounts\CreateContactLookupHash;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\PatientLoginRequest;
use App\Http\Requests\Api\PatientLoginVerifyRequest;
use App\Http\Requests\Api\RegisterPatientAccountRequest;
use App\Http\Requests\Api\RegisterPatientRequest;
use App\Http\Requests\Api\RegistrationVerifyRequest;
use App\Http\Requests\Api\StepUpOtpRequest;
use App\Http\Requests\Api\UpdateMeRequest;
use App\Http\Resources\PatientAccountResource;
use App\Http\Resources\PatientProfileResource;
use App\Models\Patient;
use App\Models\PatientAccountContact;
use App\Models\PatientLinkRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterPatientRequest $request): JsonResponse
    {
        $data = $request->validated();

        [$user, $patient] = DB::transaction(function () use ($data): array {
            $patientRole = Role::query()->where('name', 'patient')->firstOrFail();

            $nameParts = explode(' ', trim($data['name']), 2);
            $firstName = $nameParts[0] ?? '';
            $lastName = $nameParts[1] ?? '';

            $user = User::query()->create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'role_id' => $patientRole->id,
            ]);

            $patient = Patient::query()->create([
                'user_id' => $user->id,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'contact_email' => $data['email'],
                'phone' => $data['phone'] ?? null,
            ]);

            return [$user, $patient];
        });

        $user->load('role', 'patient');

        return response()->json([
            'data' => [
                'token' => $user->createToken('mobile')->plainTextToken,
                'user' => PatientProfileResource::make($user),
            ],
        ], 201);
    }

    /**
     * Step 1: Verify OTP and return registration token.
     */
    public function registrationVerify(RegistrationVerifyRequest $request, RegisterPatientAccount $register): JsonResponse
    {
        $result = $register->verifyRegistration(
            challengeId: $request->validated('challenge_id'),
            code: $request->validated('code'),
        );

        return response()->json([
            'data' => [
                'registration_token' => $result['registration_token'],
                'expires_at' => $result['expires_at']->toISOString(),
                'contact_type' => $result['contact_type'],
            ],
        ]);
    }

    /**
     * Step 2: Complete registration with profile data.
     */
    public function registerWithOtp(RegisterPatientAccountRequest $request, RegisterPatientAccount $register): JsonResponse
    {
        $result = $register->handle($request->validated());

        if ($result['contact_already_owned'] ?? false) {
            $contactLabel = $result['contact_type'] === 'email' ? 'email address' : 'phone number';

            return response()->json([
                'error' => [
                    'code' => 'CONTACT_ALREADY_OWNED',
                    'message' => "This {$contactLabel} is already registered.",
                ],
            ], 422);
        }

        $user = $result['user'];
        $user->load('role', 'contacts');

        return response()->json([
            'data' => [
                'token' => $result['token'],
                'user' => PatientAccountResource::make($user),
                'email_verification_required' => $result['email_verification_required'] ?? false,
            ],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $request->authenticate();
        $user->load('role', 'patient');

        return response()->json([
            'data' => [
                'token' => $user->createToken('mobile')->plainTextToken,
                'user' => PatientProfileResource::make($user),
            ],
        ]);
    }

    public function patientLogin(PatientLoginRequest $request, BeginPatientLogin $login): JsonResponse
    {
        $result = $login->handle(
            contactValue: $request->validated('contact_value'),
            password: $request->validated('password'),
            deviceName: $request->validated('device_name'),
            installationId: $request->validated('installation_id'),
        );

        if (! $result['step_up_required']) {
            $user = $result['user'];
            $user->load('role', 'contacts');

            return response()->json([
                'data' => [
                    'step_up_required' => false,
                    'token' => $result['token'],
                    'user' => PatientAccountResource::make($user),
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'step_up_required' => $result['step_up_required'],
                'challenge_id' => $result['challenge_id'],
                'expires_at' => $result['expires_at']->toISOString(),
            ],
        ]);
    }

    public function patientLoginVerify(PatientLoginVerifyRequest $request, IssuePatientDeviceToken $issueToken): JsonResponse
    {
        $result = $issueToken->handle(
            challengeId: $request->validated('challenge_id'),
            code: $request->validated('code'),
            deviceName: $request->validated('device_name'),
            installationId: $request->validated('installation_id'),
        );

        $user = $result['user'];
        $user->load('role', 'contacts');

        return response()->json([
            'data' => [
                'token' => $result['token'],
                'user' => PatientAccountResource::make($user),
            ],
        ]);
    }

    public function user(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load('role', 'contacts', 'patient');

        return response()->json([
            'data' => PatientAccountResource::make($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json();
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json(null, 204);
    }

    public function update(UpdateMeRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $accountFields = array_intersect_key($validated, array_flip(['first_name', 'middle_name', 'last_name']));

        DB::transaction(function () use ($user, $accountFields): void {
            if ($accountFields !== []) {
                $user->update($accountFields);
            }
        });

        $user->load('role', 'contacts');

        return response()->json([
            'data' => PatientAccountResource::make($user),
        ]);
    }

    /**
     * Request step-up OTP for sensitive changes.
     */
    public function requestStepUp(StepUpOtpRequest $request, IssueOtpChallenge $issueOtp, DispatchOtpChallenge $dispatch): JsonResponse
    {
        $user = $request->user();

        // Find the user's primary contact
        $primaryContact = $user->contacts()
            ->where('is_primary', true)
            ->whereNotNull('verified_at')
            ->first();

        if ($primaryContact === null) {
            return response()->json([
                'error' => [
                    'code' => 'NO_VERIFIED_CONTACT',
                    'message' => 'No verified contact found for step-up verification.',
                ],
            ], 422);
        }

        $result = $issueOtp->handle(
            contactType: $primaryContact->type,
            contactValue: $primaryContact->encrypted_destination,
            purpose: OtpPurpose::SensitiveChange,
            userId: $user->id,
        );

        return response()->json([
            'data' => [
                'challenge_id' => $result['challenge']->public_id,
                'expires_at' => $result['challenge']->expires_at->toISOString(),
                'contact_type' => $primaryContact->type,
                'masked_contact' => $this->maskContact($primaryContact->encrypted_destination, $primaryContact->type),
            ],
        ]);
    }

    /**
     * Verify step-up OTP and return proof token.
     */
    public function verifyStepUp(Request $request, VerifyStepUpOtp $verifyStepUp): JsonResponse
    {
        $request->validate([
            'challenge_id' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $stepUpToken = $verifyStepUp->handle(
            challengeId: $request->input('challenge_id'),
            code: $request->input('code'),
            user: $request->user(),
        );

        return response()->json([
            'data' => [
                'step_up_token' => $stepUpToken,
                'expires_in' => 900, // 15 minutes
            ],
        ]);
    }

    /**
     * Change password with step-up verification.
     * Step-up token is validated by require.step-up middleware via X-Step-Up-Token header.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ]);

        $user = $request->user();

        // Verify current password
        if (! Hash::check($request->input('current_password'), $user->password)) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_PASSWORD',
                    'message' => 'The current password is incorrect.',
                ],
            ], 422);
        }

        // Update password
        $user->update([
            'password' => Hash::make($request->input('password')),
        ]);

        // Revoke other tokens
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json([
            'data' => [
                'message' => 'Password changed successfully.',
            ],
        ]);
    }

    /**
     * Request recovery OTP.
     */
    public function recoveryOtp(Request $request, RecoverPatientPassword $recover, IssueOtpChallenge $issueOtp, DispatchOtpChallenge $dispatch): JsonResponse
    {
        $request->validate(['contact_value' => ['required', 'string']]);

        $user = $recover->findUserByContact($request->input('contact_value'));

        if ($user !== null) {
            $result = $issueOtp->handle(
                contactType: 'phone',
                contactValue: $request->input('contact_value'),
                purpose: OtpPurpose::PasswordRecovery,
                userId: $user->id,
            );

        }

        return response()->json([
            'data' => [
                'message' => 'If the contact is associated with an account, a recovery code has been sent.',
            ],
        ]);
    }

    /**
     * Verify recovery OTP and reset password.
     */
    public function recoveryVerify(Request $request, RecoverPatientPassword $recover): JsonResponse
    {
        $request->validate([
            'challenge_id' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'installation_id' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $recover->handle(
            challengeId: $request->input('challenge_id'),
            code: $request->input('code'),
            newPassword: $request->input('password'),
            deviceName: $request->input('device_name'),
            installationId: $request->input('installation_id'),
        );

        $user = $result['user'];
        $user->load('role', 'contacts');

        return response()->json([
            'data' => [
                'token' => $result['token'],
                'user' => PatientAccountResource::make($user),
            ],
        ]);
    }

    protected function maskContact(string $value, string $type): string
    {
        if ($type === 'email') {
            $parts = explode('@', $value);
            if (count($parts) === 2) {
                return substr($parts[0], 0, 1).'***@'.$parts[1];
            }
        }

        if (strlen($value) >= 4) {
            return substr($value, 0, 3).'***'.substr($value, -4);
        }

        return '***';
    }

    /**
     * List verified and pending contacts.
     */
    public function listContacts(Request $request): JsonResponse
    {
        $contacts = $request->user()->contacts()
            ->orderByDesc('is_primary')
            ->orderBy('type')
            ->get()
            ->map(fn ($contact) => [
                'id' => $contact->id,
                'type' => $contact->type,
                'masked_value' => $this->maskContact($contact->encrypted_value, $contact->type),
                'is_primary' => $contact->is_primary,
                'verified_at' => $contact->verified_at?->toISOString(),
            ]);

        return response()->json(['data' => $contacts]);
    }

    /**
     * Request OTP to verify a new contact.
     */
    public function requestContactOtp(Request $request, IssueOtpChallenge $issueOtp, DispatchOtpChallenge $dispatch): JsonResponse
    {
        $request->validate([
            'contact_type' => ['required', 'in:email,phone'],
            'contact_value' => ['required', 'string'],
        ]);

        $user = $request->user();

        // Check if contact already owned by another account
        $lookupHash = app(CreateContactLookupHash::class);
        $hash = $request->input('contact_type') === 'email'
            ? $lookupHash->forEmail($request->input('contact_value'))
            : $lookupHash->forPhone($request->input('contact_value'));

        $existing = PatientAccountContact::where('lookup_hash', $hash)
            ->where('type', $request->input('contact_type'))
            ->first();

        if ($existing !== null && $existing->user_id !== $user->id) {
            return response()->json([
                'error' => [
                    'code' => 'CONTACT_ALREADY_OWNED',
                    'message' => 'This contact is already verified by another account.',
                ],
            ], 422);
        }

        if ($existing !== null && $existing->user_id === $user->id && $existing->verified_at !== null) {
            return response()->json([
                'error' => [
                    'code' => 'CONTACT_ALREADY_VERIFIED',
                    'message' => 'You already have this contact verified.',
                ],
            ], 422);
        }

        $result = $issueOtp->handle(
            contactType: $request->input('contact_type'),
            contactValue: $request->input('contact_value'),
            purpose: OtpPurpose::AddContact,
            userId: $user->id,
        );

        return response()->json([
            'data' => [
                'challenge_id' => $result['challenge']->public_id,
                'expires_at' => $result['challenge']->expires_at->toISOString(),
            ],
        ]);
    }

    /**
     * Verify a contact OTP.
     */
    public function verifyContact(Request $request, VerifyOtpChallenge $verifyOtp): JsonResponse
    {
        $request->validate([
            'challenge_id' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $user = $request->user();

        $challenge = $verifyOtp->handle(
            challengeId: $request->input('challenge_id'),
            code: $request->input('code'),
            expectedPurpose: OtpPurpose::AddContact,
            expectedUserId: $user->id,
        );

        $contactType = $challenge->channel;
        $destination = $challenge->encrypted_destination;

        $lookupHash = app(CreateContactLookupHash::class);
        $hash = $contactType === 'email'
            ? $lookupHash->forEmail($destination)
            : $lookupHash->forPhone($destination);

        // Create or update the contact
        $contact = PatientAccountContact::updateOrCreate(
            ['user_id' => $user->id, 'type' => $contactType],
            [
                'encrypted_value' => $destination,
                'lookup_hash' => $hash,
                'verified_at' => now(),
                'is_primary' => false,
            ],
        );

        if ($contactType === 'email') {
            $user->forceFill([
                'email' => $destination,
                'email_verified_at' => now(),
            ])->save();
        } elseif ($contactType === 'phone') {
            $user->forceFill(['phone' => $destination])->save();
        }

        return response()->json([
            'data' => [
                'id' => $contact->id,
                'type' => $contact->type,
                'masked_value' => $this->maskContact($contact->encrypted_value, $contact->type),
                'is_primary' => $contact->is_primary,
                'verified_at' => $contact->verified_at->toISOString(),
            ],
        ]);
    }

    /**
     * Set a contact as primary. Requires step-up.
     */
    public function setPrimaryContact(Request $request): JsonResponse
    {
        $contact = PatientAccountContact::where('id', $request->route('contact'))
            ->where('user_id', $request->user()->id)
            ->first();

        if ($contact === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if ($contact->verified_at === null) {
            return response()->json([
                'error' => [
                    'code' => 'CONTACT_NOT_VERIFIED',
                    'message' => 'Cannot set an unverified contact as primary.',
                ],
            ], 422);
        }

        DB::transaction(function () use ($request, $contact) {
            $request->user()->contacts()
                ->where('is_primary', true)
                ->update(['is_primary' => false]);

            $contact->update(['is_primary' => true]);
        });

        $contacts = $request->user()->contacts()
            ->orderByDesc('is_primary')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'type' => $c->type,
                'masked_value' => $this->maskContact($c->encrypted_value, $c->type),
                'is_primary' => $c->is_primary,
                'verified_at' => $c->verified_at?->toISOString(),
            ]);

        return response()->json(['data' => $contacts]);
    }

    /**
     * Remove a contact. Requires step-up.
     */
    public function removeContact(Request $request): JsonResponse
    {
        $contact = PatientAccountContact::where('id', $request->route('contact'))
            ->where('user_id', $request->user()->id)
            ->first();

        if ($contact === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $verifiedContacts = $request->user()->contacts()
            ->whereNotNull('verified_at')
            ->count();

        if ($verifiedContacts <= 1) {
            return response()->json([
                'error' => [
                    'code' => 'LAST_CONTACT_REMAINING',
                    'message' => 'Cannot remove the last verified login contact.',
                ],
            ], 422);
        }

        $contact->delete();

        return response()->json(null, 204);
    }

    /**
     * Get link state.
     */
    public function linkStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->patient !== null) {
            return response()->json([
                'data' => [
                    'status' => 'linked',
                    'linked_at' => $user->patient->updated_at?->toISOString(),
                ],
            ]);
        }

        $pendingRequest = PatientLinkRequest::where('user_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if ($pendingRequest !== null) {
            return response()->json([
                'data' => [
                    'status' => 'pending_review',
                    'request_submitted_at' => $pendingRequest->created_at->toISOString(),
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'status' => 'unlinked',
            ],
        ]);
    }

    /**
     * Return current policy metadata.
     */
    public function policies(): JsonResponse
    {
        return response()->json([
            'data' => [
                'privacy_policy' => [
                    'version' => config('app.privacy_policy_version', '2026-08'),
                    'url' => config('app.privacy_policy_url', 'https://eyecare.example.com/privacy'),
                    'effective_date' => config('app.privacy_policy_effective_date', '2026-08-01'),
                ],
                'terms_of_service' => [
                    'version' => config('app.terms_version', '2026-08'),
                    'url' => config('app.terms_url', 'https://eyecare.example.com/terms'),
                    'effective_date' => config('app.terms_effective_date', '2026-08-01'),
                ],
            ],
        ]);
    }
}
