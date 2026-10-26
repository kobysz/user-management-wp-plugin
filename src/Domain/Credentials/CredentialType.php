<?php
/**
 * Rodzaj numeru uprawnień, który podaje rejestrujący się.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Credentials;

/**
 * `None` oznacza profesję bez numeru uprawnień (np. student, sponsor) i jest
 * pełnoprawnym przypadkiem, a nie brakiem danych.
 */
enum CredentialType: string {

	case PhysicianPwz       = 'physician_pwz';
	case NursePwz           = 'nurse_pwz';
	case DiagnosticianPwzdl = 'diagnostician_pwzdl';
	case None               = 'none';
}
