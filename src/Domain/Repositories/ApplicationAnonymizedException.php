<?php

declare( strict_types = 1 );

namespace WMDE\Fundraising\MembershipContext\Domain\Repositories;

/**
 * @license GNU GPL v2+
 * @author Jeroen De Dauw < jeroendedauw@gmail.com >
 */
class ApplicationAnonymizedException extends GetMembershipApplicationException {

	public function __construct() {
		parent::__construct( 'Tried to access an anonymized Application' );
	}

}
