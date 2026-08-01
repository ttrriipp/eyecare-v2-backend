<?php

namespace App\Enums;

enum OtpPurpose: string
{
    case Registration = 'registration';
    case LoginStepUp = 'login_step_up';
    case PasswordRecovery = 'password_recovery';
    case AddContact = 'add_contact';
    case ReplacePrimaryContact = 'replace_primary_contact';
    case InvitationAcceptance = 'invitation_acceptance';
    case SensitiveChange = 'sensitive_change';
    case StepUp = 'step_up';
}
