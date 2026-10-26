<?php
/**
 * Profesja: typ numeru uprawnień, rejestr zawodowy i adres redakcji.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Profession;

use PortalUsers\Domain\Credentials\CredentialType;

/**
 * Profesja należy do wtyczki, a nie do zwykłego pola formularza, bo wtyczka
 * czyta ją w wielu miejscach. Profesja bez numeru uprawnień (np. student,
 * sponsor) jest pełnoprawnym przypadkiem.
 */
final class Profession {

	/**
	 * Konstruktor.
	 *
	 * @param string         $key             Stały klucz profesji.
	 * @param string         $label           Nazwa wyświetlana.
	 * @param CredentialType $credentialType  Rodzaj numeru uprawnień.
	 * @param string         $registryUrl     Adres rejestru zawodowego (może być pusty).
	 * @param string         $notifyEmail     Adres redakcji dla tej profesji (może być pusty).
	 * @param string         $credentialField Klucz pola, w którym profesja podaje numer uprawnień (pusty, gdy bez numeru).
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly CredentialType $credentialType,
		public readonly string $registryUrl = '',
		public readonly string $notifyEmail = '',
		public readonly string $credentialField = ''
	) {}

	/**
	 * Czy ta profesja wymaga podania numeru uprawnień.
	 *
	 * @return bool
	 */
	public function requiresCredential(): bool {
		return CredentialType::None !== $this->credentialType;
	}

	/**
	 * Buduje profesję z zapisanej konfiguracji. Nieznany rodzaj numeru
	 * degraduje się do braku numeru, żeby zepsuta konfiguracja nie zatrzymała wtyczki.
	 * Pusta lub nietekstowa nazwa zastępowana jest kluczem, by profesja nigdy nie
	 * zniknęła z listy ani nie została bez nazwy.
	 *
	 * @param array<string, mixed> $raw Zapisana konfiguracja.
	 * @return self
	 */
	public static function fromArray( array $raw ): self {
		$key   = self::text( $raw, 'key' );
		$label = self::text( $raw, 'label' );

		return new self(
			$key,
			'' === $label ? $key : $label,
			CredentialType::tryFrom( self::text( $raw, 'credential_type' ) ) ?? CredentialType::None,
			self::text( $raw, 'registry_url' ),
			self::text( $raw, 'notify_email' ),
			self::text( $raw, 'credential_field' )
		);
	}

	/**
	 * Czyta pole tekstowe z obciętymi białymi znakami (klucz ' lekarz ' nie dopasowałby żadnego
	 * zgłoszenia); wartość nietekstowa traktowana jest jak brak.
	 *
	 * @param array<string, mixed> $raw Zapisana konfiguracja.
	 * @param string               $name Nazwa pola.
	 * @return string
	 */
	private static function text( array $raw, string $name ): string {
		$value = $raw[ $name ] ?? '';
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Zapisuje profesję do tablicy.
	 *
	 * @return array<string, string>
	 */
	public function toArray(): array {
		return array(
			'key'              => $this->key,
			'label'            => $this->label,
			'credential_type'  => $this->credentialType->value,
			'registry_url'     => $this->registryUrl,
			'notify_email'     => $this->notifyEmail,
			'credential_field' => $this->credentialField,
		);
	}
}
