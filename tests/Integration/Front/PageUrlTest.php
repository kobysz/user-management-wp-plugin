<?php
/**
 * Testy reguły „adres strony wskazanej w ustawieniach”.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Front;

use PortalUsers\Front\PageUrl;
use WP_UnitTestCase;

final class PageUrlTest extends WP_UnitTestCase {

	public function test_an_existing_page_gives_its_permalink(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->assertSame( get_permalink( $page ), PageUrl::for( $page ) );
	}

	public function test_a_page_id_of_zero_or_a_deleted_page_falls_back_to_the_home_page(): void {
		// Strona usunięta z panelu nie może dać pustego adresu: przekierowanie na pusty łańcuch
		// zostawia człowieka na stronie, z której przyszedł, bez komunikatu.
		$deleted = self::factory()->post->create( array( 'post_type' => 'page' ) );
		wp_delete_post( $deleted, true );

		$this->assertSame( home_url( '/' ), PageUrl::for( 0 ) );
		$this->assertSame( home_url( '/' ), PageUrl::for( $deleted ) );
		$this->assertNotSame( '', PageUrl::for( $deleted ) );
	}

	public function test_a_draft_or_trashed_page_still_gives_an_address_not_the_home_page(): void {
		// Ta semantyka jest ŚWIADOMA (zachowanie `Confirmation::base_url()` z Planu 3) i od
		// poprawki `page_issues()` jest NOŚNA: ostrzeżenie w panelu istnieje właśnie dlatego, że
		// strona robocza albo w koszu daje tu odnośnik, a nie odwrót na stronę główną. Gdyby
		// `PageUrl` zaczęło traktować szkic jak brak strony, ostrzeżenie przestałoby być potrzebne
		// i nikt by nie zauważył, że zniknął jego powód.
		$draft = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);
		$trash = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'trash',
			)
		);

		foreach ( array( $draft, $trash ) as $pageId ) {
			$this->assertNotSame( '', PageUrl::for( $pageId ) );
			$this->assertNotSame( home_url( '/' ), PageUrl::for( $pageId ), 'Strona nieopublikowana nie wraca na stronę główną: o tym ostrzega ekran ustawień.' );
			$this->assertSame( PageUrl::for( $pageId ), PageUrl::existing( $pageId ), 'Oba warianty muszą czytać stan strony tak samo.' );
		}
	}

	public function test_the_variant_without_the_fallback_says_when_there_is_no_page(): void {
		// Dla odnośnika w mailu strona główna jest złą odpowiedzią, więc `existing()` oddaje `''`
		// i wywołujący sam decyduje, co z brakiem strony zrobić.
		$page    = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$deleted = self::factory()->post->create( array( 'post_type' => 'page' ) );
		wp_delete_post( $deleted, true );

		$this->assertSame( PageUrl::for( $page ), PageUrl::existing( $page ), 'Dla istniejącej strony oba warianty muszą dać ten sam adres.' );
		$this->assertSame( '', PageUrl::existing( 0 ) );
		$this->assertSame( '', PageUrl::existing( $deleted ) );
	}
}
