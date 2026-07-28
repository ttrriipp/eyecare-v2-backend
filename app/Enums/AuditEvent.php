<?php

namespace App\Enums;

enum AuditEvent: string
{
    case AppointmentConfirmed = 'appointment.confirmed';
    case AppointmentCancelled = 'appointment.cancelled';
    case AppointmentRescheduled = 'appointment.rescheduled';
    case AppointmentCheckedIn = 'appointment.checked_in';
    case AppointmentCompleted = 'appointment.completed';
    case AppointmentNoShow = 'appointment.no_show';

    case EncounterStarted = 'encounter.started';
    case EncounterCompleted = 'encounter.completed';
    case EncounterAmended = 'encounter.amended';

    case PrescriptionFinalized = 'prescription.finalized';
    case PrescriptionAmended = 'prescription.amended';
    case PrescriptionPrinted = 'prescription.printed';

    case IntakeSubmitted = 'intake.submitted';
    case IntakeVerified = 'intake.verified';
    case IntakeReturnedForCorrection = 'intake.returned_for_correction';

    case QuotationCreated = 'quotation.created';
    case QuotationPresented = 'quotation.presented';
    case QuotationAccepted = 'quotation.accepted';
    case QuotationDeclined = 'quotation.declined';
    case QuotationExpired = 'quotation.expired';

    case JobOrderCreated = 'job_order.created';
    case JobOrderStatusChanged = 'job_order.status_changed';
    case JobOrderCancelled = 'job_order.cancelled';

    case InvoiceIssued = 'invoice.issued';
    case InvoiceVoided = 'invoice.voided';
    case PaymentRecorded = 'payment.recorded';
    case PaymentCorrected = 'payment.corrected';

    case DispensingCompleted = 'dispensing.completed';

    case InventoryRecorded = 'inventory.recorded';

    case RatingSubmitted = 'rating.submitted';
    case RatingModerated = 'rating.moderated';

    case HealthRecordPrinted = 'health_record.printed';
    case UserCreated = 'user.created';
    case UserRoleChanged = 'user.role_changed';

    case ProductCreated = 'product.created';
    case ProductUpdated = 'product.updated';
    case ProductArchived = 'product.archived';

    case OrderStatusChanged = 'order.status_changed';

    case BillingGenerated = 'billing.generated';
    case BillingBalanceRecalculated = 'billing.balance_recalculated';
}
