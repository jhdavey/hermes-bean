<?php

namespace Tests\Unit;

use App\Data\HermesSemanticOperation;
use App\Services\HermesSemanticProtocol;
use PHPUnit\Framework\TestCase;

class HermesSemanticProtocolTest extends TestCase
{
    public function test_canonical_protocol_preserves_the_existing_typed_chat_prompts_and_schemas(): void
    {
        $protocol = new HermesSemanticProtocol;

        $this->assertSame(2, HermesSemanticProtocol::SCHEMA_VERSION);
        $this->assertSame('bean_semantic_interpretation_v2', HermesSemanticProtocol::INTERPRETATION_SCHEMA_NAME);
        $this->assertSame('bean_grounded_response_v1', HermesSemanticProtocol::COMPOSITION_SCHEMA_NAME);
        $this->assertSame(
            '7b55066b8e97e6ab790b920b287a63d121d6f0f7ff322dd450715e9284c8cb24',
            hash('sha256', serialize($protocol->interpretationInstructions())),
        );
        $this->assertSame(
            'f79225e9c2de29c76fea3b07853d88151147d678e699aa27b5a65e7b4be9c32f',
            hash('sha256', serialize($protocol->compositionInstructions())),
        );
        $this->assertSame(
            '8de2bde347e03d4a6740e4ab6eec51f18dd383dc06480b88bff08ad3baf0c3be',
            hash('sha256', serialize($protocol->interpretationSchema())),
        );
        $this->assertSame(
            'b8c0bcffcb4329a933bece29d8ef99b68b1beaaed6d933d07db6be5d285731bc',
            hash('sha256', serialize($protocol->compositionSchema())),
        );
    }

    public function test_interpretation_protocol_exposes_one_strict_complete_tool_contract(): void
    {
        $protocol = new HermesSemanticProtocol;
        $schema = $protocol->interpretationSchema();
        $instructions = $protocol->interpretationInstructions();

        $this->assertFalse($schema['additionalProperties']);
        $this->assertFalse($schema['properties']['operations']['items']['additionalProperties']);
        $this->assertSame(
            HermesSemanticOperation::TOOLS,
            $schema['properties']['operations']['items']['properties']['tool']['enum'],
        );
        $this->assertSame(
            ['id', 'tool', 'arguments_json', 'dependencies'],
            $schema['properties']['operations']['items']['required'],
        );
        foreach (HermesSemanticOperation::TOOLS as $tool) {
            $this->assertStringContainsString($tool, $instructions);
        }
        $this->assertStringContainsString('“can you hear me?” may use outcome "respond"', $instructions);
        $this->assertStringContainsString('never a deterministic voice-state shortcut', $instructions);
    }

    public function test_composition_protocol_requires_only_the_grounded_final_and_directives(): void
    {
        $schema = (new HermesSemanticProtocol)->compositionSchema();

        $this->assertSame('object', $schema['type']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(
            ['response_text', 'close_after_response', 'response_expected'],
            $schema['required'],
        );
        $this->assertSame(
            ['response_text', 'close_after_response', 'response_expected'],
            array_keys($schema['properties']),
        );
    }
}
