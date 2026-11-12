<?php
namespace BetaAI\Search\AI;

defined( 'ABSPATH' ) || exit;

class AIException extends \RuntimeException {
	public function __construct( public readonly string $safe_code, public readonly int $http_status = 0, string $safe_message = 'AI request failed.' ) {
		parent::__construct( $safe_message );
	}
}
