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
            'capability' => $record->capability,
            'provider' => $record->provider,
            'model' => $record->model,
            'status' => $record->status,
            'http_status' => $record->httpStatus,
            'cost_estimate' => $record->costEstimate,
            'cost_actual' => $record->costActual,
            'currency' => $record->currency,
            'input_tokens' => $record->inputTokens,
            'output_tokens' => $record->outputTokens,
            'duration_ms' => $record->durationMs,
            'request_fingerprint' => $record->requestFingerprint,
            'error_class' => $record->errorClass === null ? null : mb_substr($record->errorClass, 0, 120),
            'error_message' => $record->errorMessage,
        ]));
    }
}
