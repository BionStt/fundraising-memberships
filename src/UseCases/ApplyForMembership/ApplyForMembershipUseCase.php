<?php

declare( strict_types = 1 );

namespace WMDE\Fundraising\MembershipContext\UseCases\ApplyForMembership;

use WMDE\Fundraising\MembershipContext\Infrastructure\TemplateMailerInterface;
use WMDE\Fundraising\MembershipContext\Authorization\ApplicationTokenFetcher;
use WMDE\Fundraising\MembershipContext\Domain\Model\Application;
use WMDE\Fundraising\MembershipContext\Domain\Repositories\ApplicationRepository;
use WMDE\Fundraising\MembershipContext\Tracking\ApplicationPiwikTracker;
use WMDE\Fundraising\MembershipContext\Tracking\ApplicationTracker;
use WMDE\Fundraising\PaymentContext\Domain\Model\PaymentMethod;
use WMDE\Fundraising\PaymentContext\Domain\PaymentDelayCalculator;

/**
 * @license GNU GPL v2+
 */
class ApplyForMembershipUseCase {

	private $repository;
	private $tokenFetcher;
	private $mailer;
	private $validator;
	private $policyValidator;
	private $membershipApplicationTracker;
	private $piwikTracker;
	private $paymentDelayCalculator;

	public function __construct( ApplicationRepository $repository,
		ApplicationTokenFetcher $tokenFetcher, TemplateMailerInterface $mailer,
		MembershipApplicationValidator $validator, ApplyForMembershipPolicyValidator $policyValidator,
		ApplicationTracker $tracker, ApplicationPiwikTracker $piwikTracker,
		PaymentDelayCalculator $paymentDelayCalculator ) {

		$this->repository = $repository;
		$this->tokenFetcher = $tokenFetcher;
		$this->mailer = $mailer;
		$this->validator = $validator;
		$this->policyValidator = $policyValidator;
		$this->membershipApplicationTracker = $tracker;
		$this->piwikTracker = $piwikTracker;
		$this->paymentDelayCalculator = $paymentDelayCalculator;
	}

	public function applyForMembership( ApplyForMembershipRequest $request ): ApplyForMembershipResponse {
		$validationResult = $this->validator->validate( $request );
		if ( !$validationResult->isSuccessful() ) {
			// TODO: return failures (note that we have infrastructure failures that are not ConstraintViolations)
			return ApplyForMembershipResponse::newFailureResponse( $validationResult );
		}

		$application = $this->newApplicationFromRequest( $request );

		if ( $this->policyValidator->needsModeration( $application ) ) {
			$application->markForModeration();
		}

		if ( $this->policyValidator->isAutoDeleted( $application ) ) {
			$application->markAsDeleted();
		}

		$application->notifyOfFirstPaymentDate( $this->paymentDelayCalculator->calculateFirstPaymentDate()->format( 'Y-m-d' ) );

		// TODO: handle exceptions
		$this->repository->storeApplication( $application );

		// TODO: handle exceptions
		$this->membershipApplicationTracker->trackApplication( $application->getId(), $request->getTrackingInfo() );

		// TODO: handle exceptions
		$this->piwikTracker->trackApplication( $application->getId(), $request->getPiwikTrackingString() );

		// TODO: handle exceptions
		if ( $this->isAutoConfirmed( $application ) ) {
			$this->sendConfirmationEmail( $application );
		}

		// TODO: handle exceptions
		$tokens = $this->tokenFetcher->getTokens( $application->getId() );

		return ApplyForMembershipResponse::newSuccessResponse(
			$tokens->getAccessToken(),
			$tokens->getUpdateToken(),
			$application
		);
	}

	private function newApplicationFromRequest( ApplyForMembershipRequest $request ): Application {
		return ( new MembershipApplicationBuilder() )->newApplicationFromRequest( $request );
	}

	private function sendConfirmationEmail( Application $application ): void {
		$this->mailer->sendMail(
			$application->getApplicant()->getEmailAddress(),
			[
				'membershipType' => $application->getType(),
				'membershipFee' => $application->getPayment()->getAmount()->getEuroString(),
				'paymentIntervalInMonths' => $application->getPayment()->getIntervalInMonths(),
				'paymentType' => $application->getPayment()->getPaymentMethod()->getId(),
				'salutation' => $application->getApplicant()->getName()->getSalutation(),
				'title' => $application->getApplicant()->getName()->getTitle(),
				'lastName' => $application->getApplicant()->getName()->getLastName(),
				'firstName' => $application->getApplicant()->getName()->getFirstName(),
				'hasReceiptEnabled' => $application->getDonationReceipt()
			]
		);
	}

	public function isAutoConfirmed( Application $application ): bool {
		return $application->getPayment()->getPaymentMethod()->getId() === PaymentMethod::DIRECT_DEBIT;
	}
}
