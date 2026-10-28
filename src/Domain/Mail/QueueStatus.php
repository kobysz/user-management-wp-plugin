<?php
/**
 * Stan wpisu w kolejce maili.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Mail;

/**
 * Stan wpisu w kolejce: czeka, jest wysyłany, poszedł albo wyczerpał próby.
 */
enum QueueStatus: string {

	case Queued  = 'queued';
	case Sending = 'sending';
	case Sent    = 'sent';
	case Failed  = 'failed';
}
