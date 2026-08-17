<?php

namespace App\Enums;

enum AuditEvent: string
{
    case AppointmentConfirmed = 'appointment.confirmed';
    case AppointmentCancelled = 'appointment.cancelled';
    case AppointmentRescheduled = 'appointment.rescheduled';
    case AppointmentOptometristAssigned = 'appointment.optometrist_assigned';
    case AppointmentStatusChanged = 'appointment.status_changed';
    case AppointmentCheckedIn = 'appointment.checked_in';
    case AppointmentCompleted = 'appointment.completed';
    case AppointmentNoShow = 'appointment.no_show';
    case AppointmentRequestSubmitted = 'appointment_request.submitted';
    case AppointmentRequestAccepted = 'appointment_request.accepted';
    case AppointmentRequestRejected = 'appointment_request.rejected';
    case AppointmentRequestCancelled = 'appointment_request.cancelled';
    case AppointmentRequestExpired = 'appointment_request.expired';
    case AppointmentRequestLinked = 'appointment_request.linked';

    case ClinicHoursUpdated = 'clinic_hours.updated';
    case ProviderHoursUpdated = 'provider_hours.updated';
    case ScheduleOverrideCreated = 'schedule_override.created';
    case ScheduleOverrideRemoved = 'schedule_override.removed';

    case EncounterStarted = 'encounter.started';
    case EncounterCompleted = 'encounter.completed';
    case EncounterAmended = 'encounter.amended';
    case EncounterProviderAssigned = 'encounter.provider_assigned';
    case EncounterTransferred = 'encounter.transferred';
    case EncounterVoided = 'encounter.voided';

    case PrescriptionFinalized = 'prescription.finalized';
    case PrescriptionAmended = 'prescription.amended';
    case PrescriptionPrinted = 'prescription.printed';
    case PrescriptionVoided = 'prescription.voided';

    case QuotationCreated = 'quotation.created';
    case QuotationPresented = 'quotation.presented';
    case QuotationAccepted = 'quotation.accepted';
    case QuotationDeclined = 'quotation.declined';
    case QuotationExpired = 'quotation.expired';
    case QuotationRevised = 'quotation.revised';

    case JobOrderCreated = 'job_order.created';
    case JobOrderStatusChanged = 'job_order.status_changed';
    case JobOrderCancelled = 'job_order.cancelled';

    case InvoiceIssued = 'invoice.issued';
    case InvoiceVoided = 'invoice.voided';
    case PaymentRecorded = 'payment.recorded';
    case PaymentCorrected = 'payment.corrected';
    case BillingRecordCreated = 'billing_record.created';
    case BillingChargesAdded = 'billing_record.charges_added';
    case BillingDiscountChanged = 'billing_record.discount_changed';
    case BillingRecordVoided = 'billing_record.voided';
    case BillingRecordPaymentRecorded = 'billing_record.payment_recorded';
    case BillingRecordPaymentCorrected = 'billing_record.payment_corrected';
    case BillingRecordDispensed = 'billing_record.dispensed';

    case DispensingCompleted = 'dispensing.completed';

    case InventoryRecorded = 'inventory.recorded';
    case InventoryMovementRecorded = 'inventory.movement_recorded';
    case InventoryCommitted = 'inventory.order_committed';
    case InventoryReversed = 'inventory.order_reversed';

    case RatingSubmitted = 'rating.submitted';
    case RatingModerated = 'rating.moderated';

    case HealthRecordPrinted = 'health_record.printed';
    case EncounterPrinted = 'encounter.printed';
    case UserCreated = 'user.created';
    case UserRoleChanged = 'user.role_changed';

    case UserLoggedIn = 'user.logged_in';
    case UserLoggedOut = 'user.logged_out';
    case UserLoginFailed = 'user.login_failed';
    case UserPasswordChanged = 'user.password_changed';
    case UserDeactivated = 'user.deactivated';
    case UserReactivated = 'user.reactivated';

    case ProductCreated = 'product.created';
    case ProductUpdated = 'product.updated';
    case ProductArchived = 'product.archived';
    case ProductDeleted = 'product.deleted';

    case CatalogCreated = 'catalog.created';
    case CatalogUpdated = 'catalog.updated';
    case CatalogActivated = 'catalog.activated';
    case CatalogDeactivated = 'catalog.deactivated';
    case CatalogDeleted = 'catalog.deleted';

    case PatientUpdated = 'patient.updated';
    case PatientAccountLinked = 'patient_account.linked';
    case PatientAccountUnlinked = 'patient_account_unlinked';
    case PatientLinkApproved = 'patient_link_request.approved';
    case PatientLinkRejected = 'patient_link_request.rejected';

    case PrivacyRequestProcessed = 'privacy_request.processed';
    case PrivacyIncidentUpdated = 'privacy_incident.updated';

    case OrderStatusChanged = 'order.status_changed';

    case BillingGenerated = 'billing.generated';
    case BillingBalanceRecalculated = 'billing.balance_recalculated';

    case ArAssetUploaded = 'ar_asset.uploaded';
    case ArAssetValidated = 'ar_asset.validated';
    case ArAssetRejected = 'ar_asset.rejected';
    case ArAssetApproved = 'ar_asset.approved';
    case ArAssetPublished = 'ar_asset.published';
    case ArAssetReplaced = 'ar_asset.replaced';
    case ArAssetDisabled = 'ar_asset.disabled';
    case ArAssetRolledBack = 'ar_asset.rolled_back';
}
