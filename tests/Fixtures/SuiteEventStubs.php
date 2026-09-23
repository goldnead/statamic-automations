<?php

/**
 * Stand-ins for the events of the Suite build of 23.09.2026: the new
 * subscription events of statamic-payments, the course events of
 * statamic-courses, the partner events of statamic-affiliates and the
 * declined offer of statamic-funnels.
 *
 * Same rules as {@see CommerceEventStubs}: the real class names, because the
 * listener maps on `$event::class`; the real constructor signatures, property
 * names included; and a guard on every declaration, so an installed sibling
 * wins and this file steps aside.
 */

namespace Goldnead\StatamicPayments\Events {
    if (! class_exists(SubscriptionPaused::class, false)) {
        class SubscriptionPaused
        {
            public function __construct(
                public readonly object $subscription,
                public readonly ?\DateTimeInterface $resumesAt,
                public readonly string $by,
            ) {}
        }
    }

    if (! class_exists(SubscriptionResumed::class, false)) {
        class SubscriptionResumed
        {
            public function __construct(
                public readonly object $subscription,
                public readonly string $by,
            ) {}
        }
    }

    if (! class_exists(SubscriptionPaymentUpcoming::class, false)) {
        class SubscriptionPaymentUpcoming
        {
            public function __construct(
                public readonly object $subscription,
                public readonly \DateTimeInterface $dueAt,
                public readonly int $daysBefore,
            ) {}
        }
    }

    if (! class_exists(SubscriptionCardExpiring::class, false)) {
        class SubscriptionCardExpiring
        {
            public function __construct(
                public readonly object $subscription,
                public readonly \DateTimeInterface $expiresAt,
            ) {}
        }
    }

    if (! class_exists(SubscriptionCardExpired::class, false)) {
        class SubscriptionCardExpired
        {
            public function __construct(
                public readonly object $subscription,
                public readonly \DateTimeInterface $expiredAt,
            ) {}
        }
    }

    if (! class_exists(SubscriptionAttemptFailed::class, false)) {
        class SubscriptionAttemptFailed
        {
            public function __construct(
                public readonly object $subscription,
                public readonly object $payment,
                public readonly int $attempt,
            ) {}
        }
    }

    if (! class_exists(SubscriptionPlanCompleted::class, false)) {
        class SubscriptionPlanCompleted
        {
            public function __construct(
                public readonly object $subscription,
                public readonly object $payment,
            ) {}
        }
    }

    if (! class_exists(SubscriptionChanged::class, false)) {
        class SubscriptionChanged
        {
            public function __construct(
                public readonly object $subscription,
                public readonly string $fromProduct,
                public readonly string $toProduct,
                public readonly int $fromAmountCent,
                public readonly int $toAmountCent,
                public readonly int $prorationCent,
                public readonly ?object $prorationPayment,
                public readonly bool $immediate,
                public readonly string $by,
            ) {}
        }
    }

    if (! class_exists(SubscriptionReplaced::class, false)) {
        class SubscriptionReplaced
        {
            public function __construct(
                public readonly object $replaced,
                public readonly object $purchase,
                public readonly ?object $replacement,
                public readonly int $creditCent,
                public readonly int $creditDays,
            ) {}
        }
    }

    if (! class_exists(CheckoutBlocked::class, false)) {
        class CheckoutBlocked
        {
            public function __construct(
                public readonly string $reason,
                public readonly ?string $email,
                public readonly ?string $ip,
            ) {}
        }
    }

    if (! class_exists(PaymentChargedBack::class, false)) {
        class PaymentChargedBack
        {
            public function __construct(
                public readonly object $payment,
                public readonly string $reference,
                public readonly int $amountCent,
                public readonly ?string $reason = null,
            ) {}
        }
    }
}

namespace Goldnead\Courses\Events {
    if (! class_exists(LearnerEnrolled::class, false)) {
        class LearnerEnrolled
        {
            public function __construct(
                public readonly string $userId,
                public readonly string $courseId,
                public readonly string $courseSlug,
            ) {}
        }
    }

    if (! class_exists(CourseCompleted::class, false)) {
        class CourseCompleted
        {
            public function __construct(
                public readonly string $userId,
                public readonly string $courseId,
                public readonly string $courseSlug,
            ) {}
        }
    }

    if (! class_exists(LessonCompleted::class, false)) {
        class LessonCompleted
        {
            public function __construct(
                public readonly object $state,
                public readonly string $source,
            ) {}
        }
    }

    if (! class_exists(LessonUnlocked::class, false)) {
        class LessonUnlocked
        {
            public function __construct(
                public readonly string $userId,
                public readonly string $courseId,
                public readonly string $courseSlug,
                public readonly string $lessonSlug,
                public readonly string $source,
            ) {}
        }
    }

    if (! class_exists(QuizPassed::class, false)) {
        class QuizPassed
        {
            public function __construct(
                public readonly string $userId,
                public readonly string $courseId,
                public readonly string $courseSlug,
                public readonly string $lessonSlug,
                public readonly string $assessment,
                public readonly int $score,
                public readonly ?string $resultKey,
                public readonly ?int $responseId,
            ) {}
        }
    }

    if (! class_exists(QuizFailed::class, false)) {
        class QuizFailed
        {
            public function __construct(
                public readonly string $userId,
                public readonly string $courseId,
                public readonly string $courseSlug,
                public readonly string $lessonSlug,
                public readonly string $assessment,
                public readonly int $score,
                public readonly ?string $resultKey,
                public readonly ?int $responseId,
            ) {}
        }
    }

    if (! class_exists(DripPaused::class, false)) {
        class DripPaused
        {
            public function __construct(
                public readonly string $userId,
                public readonly string $courseId,
                public readonly string $courseSlug,
                public readonly string $reason,
            ) {}
        }
    }

    if (! class_exists(DripResumed::class, false)) {
        class DripResumed
        {
            public function __construct(
                public readonly string $userId,
                public readonly string $courseId,
                public readonly string $courseSlug,
                public readonly int $pausedSeconds,
                public readonly string $reason,
            ) {}
        }
    }

    if (! class_exists(CourseAccessSuspended::class, false)) {
        class CourseAccessSuspended
        {
            public function __construct(
                public readonly string $userId,
                public readonly string $courseId,
                public readonly string $courseSlug,
                public readonly string $reason,
            ) {}
        }
    }

    if (! class_exists(CourseAccessRestored::class, false)) {
        class CourseAccessRestored
        {
            public function __construct(
                public readonly string $userId,
                public readonly string $courseId,
                public readonly string $courseSlug,
                public readonly string $reason,
            ) {}
        }
    }

    if (! class_exists(TeamMemberAdded::class, false)) {
        class TeamMemberAdded
        {
            public function __construct(
                public readonly string $ownerId,
                public readonly string $courseId,
                public readonly string $courseSlug,
                public readonly string $email,
                public readonly string $product = '',
            ) {}
        }
    }

    if (! class_exists(TeamMemberRemoved::class, false)) {
        class TeamMemberRemoved
        {
            public function __construct(
                public readonly string $ownerId,
                public readonly string $courseId,
                public readonly string $courseSlug,
                public readonly string $email,
                public readonly string $product = '',
            ) {}
        }
    }
}

namespace Goldnead\Affiliates\Events {
    if (! class_exists(CommissionEarned::class, false)) {
        class CommissionEarned
        {
            public function __construct(public readonly object $commission) {}
        }
    }

    if (! class_exists(CommissionReversed::class, false)) {
        class CommissionReversed
        {
            public function __construct(public readonly object $commission) {}
        }
    }

    if (! class_exists(PartnerApplied::class, false)) {
        class PartnerApplied
        {
            public function __construct(public readonly object $partner) {}
        }
    }

    if (! class_exists(PartnerApproved::class, false)) {
        class PartnerApproved
        {
            public function __construct(public readonly object $partner) {}
        }
    }
}

namespace Goldnead\StatamicFunnels\Events {
    if (! class_exists(FunnelOfferDeclined::class, false)) {
        class FunnelOfferDeclined
        {
            public function __construct(
                public readonly object $visit,
                public readonly object $step,
            ) {}
        }
    }

    if (! class_exists(UpsellDeclined::class, false)) {
        class UpsellDeclined
        {
            public function __construct(
                public readonly object $visit,
                public readonly object $step,
                public readonly string $offerHandle,
                public readonly ?object $payment,
            ) {}
        }
    }
}
