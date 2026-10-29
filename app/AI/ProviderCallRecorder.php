<?php

namespace App\AI;

use App\Models\ProviderCall;
use Illuminate\Support\Facades\DB;

final class ProviderCallRecorder
{
    public function record(ProviderCallRecord $record): ProviderCall
    {
        return DB::transaction(fn (): ProviderCall => ProviderCall::create([
            'generation_job_id' => $record->generationJobId,
            'project_id' => $record->projectId,
            'user_id' => $record->userId,
            'capability' => $record->capability,
            'operation' => $record->operation?->value,
            'provider' => $record->provider,
            'model' => $record->model,
            'status' => $record->status,
            'attempt' => $record->attempt,
            'http_status' => $record->httpStatus,
            'cost_estimate' => $record->costEstimate,
            'cost_actual' => $record->costActual,
            'currency' => $record->currency,
            'rate_version' => $record->computedCost->rateVersion,
            'cost_type' => $record->computedCost->costType->value,
            'charge_status' => $record->chargeStatus->value,
            'calculation_method' => $record->computedCost->method,
            'input_tokens' => $record->inputTokens,
            'output_tokens' => $record->outputTokens,
            'usage_details' => $record->usageDetails,
            'parameters' => $record->parameters,
            'duration_ms' => $record->durationMs,
            'request_fingerprint' => $record->requestFingerprint,
            'provider_request_id' => $record->providerRequestId,
            'error_class' => $record->errorClass === null ? null : mb_substr($record->errorClass, 0, 120),
            'error_message' => $record->errorMessage,
        ]));
    }
}
