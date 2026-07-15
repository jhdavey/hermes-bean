<?php

namespace App\Services;

use App\Data\HermesSemanticComposition;
use App\Data\HermesSemanticCompositionRequest;
use App\Data\HermesSemanticInterpretation;
use App\Data\HermesSemanticInterpretationRequest;

interface HermesSemanticInterpreter
{
    public function interpret(HermesSemanticInterpretationRequest $request): HermesSemanticInterpretation;

    public function compose(HermesSemanticCompositionRequest $request): HermesSemanticComposition;
}
