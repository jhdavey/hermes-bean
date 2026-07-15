<?php

namespace App\Data;

use InvalidArgumentException;
use JsonException;

final readonly class HermesSemanticOperationResult
{
    public const STATUSES = ['completed', 'failed', 'canceled', 'skipped'];

    /** @param array<string, mixed> $data */
    public function __construct(
        public string $operationId,
        public string $tool,
        public string $status,
        public array $data = [],
    ) {
        if (trim($this->operationId) === '') {
            throw new InvalidArgumentException('A semantic operation result requires an operation id.');
        }

        if (! in_array($this->tool, HermesSemanticOperation::TOOLS, true)) {
            throw new InvalidArgumentException('A semantic operation result used an unsupported tool.');
        }

        if (! in_array($this->status, self::STATUSES, true)) {
            throw new InvalidArgumentException('A semantic operation result must be terminal.');
        }

        try {
            json_encode($this->data, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Semantic operation result data must be JSON encodable.', previous: $exception);
        }
    }

    /** @return array{operation_id:string,tool:string,status:string,data:array<string,mixed>} */
    public function toArray(): array
    {
        return [
            'operation_id' => $this->operationId,
            'tool' => $this->tool,
            'status' => $this->status,
            'data' => $this->data,
        ];
    }
}
