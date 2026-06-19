<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Ai;

use OpenSEO\Ai\Prompts;
use PHPUnit\Framework\TestCase;

final class PromptsTest extends TestCase {

	public function test_system_meta_description_states_limit_and_language(): void {
		$system = Prompts::system_meta_description();

		$this->assertStringContainsString( '155', $system );
		$this->assertStringContainsString( 'same language', $system );
	}

	public function test_system_title_states_its_limit(): void {
		$this->assertStringContainsString( '60', Prompts::system_title() );
	}

	public function test_user_for_post_includes_title_and_content(): void {
		$user = Prompts::user_for_post( 'Hello World', 'The body text.' );

		$this->assertStringContainsString( 'Hello World', $user );
		$this->assertStringContainsString( 'The body text.', $user );
	}

	public function test_system_schema_type_lists_types_and_json_keys(): void {
		$system = Prompts::system_schema_type();

		$this->assertStringContainsString( 'FAQPage', $system );
		$this->assertStringContainsString( 'BlogPosting', $system );
		$this->assertStringContainsString( 'type', $system );
		$this->assertStringContainsString( 'reason', $system );
	}
}
