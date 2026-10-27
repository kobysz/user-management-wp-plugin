<?php
/**
 * Kontekst obsługi zgłoszenia rejestracji: plan i pola z jednej migawki źródła.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Front;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Profession\Profession;
use PortalUsers\Domain\Registration\RegistrationPlan;

/**
 * Wartość zwracana przez `RegistrationForm::context_for()`: istnieje wyłącznie dla profesji
 * oferowanej w formularzu, a plan i `AcfFields` pochodzą z tej samej instancji źródła pól.
 */
final class RegistrationContext {

	/**
	 * Konstruktor.
	 *
	 * @param Profession       $profession Profesja ze zgłoszenia (oferowana).
	 * @param RegistrationPlan $plan       Plan z macierzą przyciętą do pól źródła.
	 * @param AcfFields        $fields     Pola ACF na tej samej migawce co plan.
	 */
	public function __construct(
		public readonly Profession $profession,
		public readonly RegistrationPlan $plan,
		public readonly AcfFields $fields
	) {}
}
