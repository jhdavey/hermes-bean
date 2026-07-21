<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarkdownNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_notes_store_markdown_and_derive_searchable_plain_text(): void
    {
        $token = $this->apiToken('markdown-notes@example.com');
        $markdown = <<<'MD'
# Product brief

**Bold direction** with [reference material](https://example.com).

- [x] Editor selected
- [ ] Ship the update

| Area | Status |
| --- | --- |
| Notes | Ready |
MD;

        $response = $this->withToken($token)->postJson('/api/notes', [
            'body_markdown' => $markdown,
        ])->assertCreated();

        $response
            ->assertJsonPath('data.title', 'Product brief')
            ->assertJsonPath('data.body_markdown', $markdown)
            ->assertJsonMissingPath('data.body_html')
            ->assertJsonMissingPath('data.body_delta');

        $plainText = (string) $response->json('data.plain_text');
        $this->assertStringContainsString('Bold direction with reference material.', $plainText);
        $this->assertStringContainsString('Editor selected', $plainText);
        $this->assertStringContainsString('Notes', $plainText);

        $noteId = $response->json('data.id');
        $updatedMarkdown = "## Updated\n\n> A formatted quote.\n\n`inline code`";
        $this->withToken($token)->patchJson("/api/notes/{$noteId}", [
            'title' => 'Updated note',
            'body_markdown' => $updatedMarkdown,
        ])
            ->assertOk()
            ->assertJsonPath('data.body_markdown', $updatedMarkdown)
            ->assertJsonPath('data.plain_text', "Updated\n\nA formatted quote.\n\ninline code");

        $this->withToken($token)->getJson('/api/notes?query=formatted')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $noteId);
    }
}
