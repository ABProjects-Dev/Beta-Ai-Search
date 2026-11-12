<?php
namespace BetaAI\Search\AI;

defined( 'ABSPATH' ) || exit;

interface ProviderInterface {
	public function id(): string;
	/** @return list<array{id:string,embedding:bool,chat:bool}> */
	public function catalog(): array;
	/** @return array{vectors:list<list<float>>,model:string,usage:array}|null */
	public function embed( array $texts, string $model, int $dimensions, bool $is_query ): ?array;
	/** @return array{query:array,model:string,usage:array}|null */
	public function understand( string $text, array $context, string $model ): ?array;
	/** @return list<string> Model IDs actually returned by the provider. */
	public function list_models(): array;
}
