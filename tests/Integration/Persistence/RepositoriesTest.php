<?php
/**
 * Testy repozytoriów ustawień, profesji i macierzy pól.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Persistence;

use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Credentials\CredentialType;
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Fields\FieldState;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\OptionStore;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use WP_UnitTestCase;

final class RepositoriesTest extends WP_UnitTestCase {

	public function test_settings_repository_returns_defaults_when_option_is_missing(): void {
		delete_option( OptionStore::OPTION_SETTINGS );
		$this->assertSame( Settings::defaults()->toArray(), SettingsRepository::get()->toArray() );
	}

	public function test_settings_round_trip_through_the_option(): void {
		SettingsRepository::save( Settings::fromArray( array( 'approval_enabled' => false, 'rate_limit_per_hour' => 25 ) ) );
		$loaded = SettingsRepository::get();
		$this->assertFalse( $loaded->approvalEnabled );
		$this->assertSame( 25, $loaded->rateLimitPerHour );
	}

	public function test_profession_repository_round_trip_preserves_order_and_fields(): void {
		$collection = ProfessionCollection::fromArray(
			array(
				array(
					'key'             => 'lekarz',
					'label'           => 'Lekarz',
					'credential_type' => 'physician_pwz',
					'registry_url'    => 'https://rejestr.nil.org.pl/',
					'notify_email'    => 'redakcja@example.test',
				),
				array(
					'key'             => 'student',
					'label'           => 'Student',
					'credential_type' => 'none',
				),
			)
		);
		ProfessionRepository::save( $collection );
		$loaded = ProfessionRepository::get();
		$this->assertSame( array( 'lekarz', 'student' ), $loaded->keys() );
		$this->assertSame( CredentialType::PhysicianPwz, $loaded->get( 'lekarz' )->credentialType );
		$this->assertSame( 'redakcja@example.test', $loaded->get( 'lekarz' )->notifyEmail );
	}

	public function test_profession_repository_preserves_the_credential_field(): void {
		ProfessionRepository::save(
			ProfessionCollection::fromArray(
				array(
					array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz', 'credential_field' => 'pwz' ),
				)
			)
		);
		$this->assertSame( 'pwz', ProfessionRepository::get()->get( 'lekarz' )->credentialField );
	}

	public function test_matrix_repository_round_trip(): void {
		$matrix = FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'required', 'miasto' => 'visible' ) ) );
		FieldMatrixRepository::save( $matrix );
		$this->assertSame( array( 'pwz' ), FieldMatrixRepository::get()->requiredFields( 'lekarz' ) );
	}

	public function test_repositories_return_empty_structures_when_options_hold_garbage(): void {
		update_option( OptionStore::OPTION_PROFESSIONS, 'bzdura' );
		update_option( OptionStore::OPTION_MATRIX, 42 );
		$this->assertSame( array(), ProfessionRepository::get()->keys() );
		$this->assertSame( FieldState::Hidden, FieldMatrixRepository::get()->stateFor( 'lekarz', 'pwz' ) );
	}
}
