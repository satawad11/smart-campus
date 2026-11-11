<?php

namespace App\Console\Commands;

use App\Enums\AIModelStatus;
use App\Enums\AIModelType;
use App\Enums\AuditAction;
use App\Enums\FaceProfileStatus;
use App\Models\AIModel;
use App\Models\FaceProfile;
use App\Services\Audit\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Make one face embedding model the only active one (ACR-AI-01).
 *
 * Enrolment falls back to "any active embedding model" when the AI service reports a model
 * that is not registered, and candidate search only compares vectors of the same model.
 * Keeping exactly one active embedding model means vectors of different models never mix.
 * Faces enrolled under a deactivated model are no longer matched and must be re-enrolled,
 * so the command refuses to strand them unless --force is given.
 */
class FaceModelActivateCommand extends Command
{
    protected $signature = 'face:model-activate
        {code : Model code, e.g. yunet-sface}
        {model_version : Model version, e.g. 2021dec}
        {--force : Activate even if faces enrolled with other models will stop matching}';

    protected $description = 'Make one face embedding model the only active one (others become INACTIVE)';

    public function handle(AuditService $audit): int
    {
        $model = AIModel::query()
            ->where('model_type', AIModelType::FACE_EMBEDDING)
            ->where('code', (string) $this->argument('code'))
            ->where('version', (string) $this->argument('model_version'))
            ->first();

        if ($model === null) {
            $this->error('No FACE_EMBEDDING model with that code and version is registered.');

            return self::FAILURE;
        }

        $others = AIModel::query()
            ->where('model_type', AIModelType::FACE_EMBEDDING)
            ->where('status', AIModelStatus::ACTIVE)
            ->whereKeyNot($model->getKey())
            ->get();

        $stranded = FaceProfile::query()
            ->whereIn('recognition_model_id', $others->modelKeys())
            ->where('status', FaceProfileStatus::ACTIVE)
            ->count();

        if ($stranded > 0 && ! $this->option('force')) {
            $this->error("{$stranded} active face profile(s) were enrolled with another model and would stop matching.");
            $this->line('Re-enrol them after activation, then rerun with --force.');

            return self::FAILURE;
        }

        if ($model->status === AIModelStatus::ACTIVE && $model->is_default && $others->isEmpty()) {
            $this->info("{$model->code} {$model->version} is already the only active embedding model.");

            return self::SUCCESS;
        }

        $previous = ['status' => $model->status->value, 'is_default' => (bool) $model->is_default];

        DB::transaction(function () use ($model, $others, $audit, $previous, $stranded): void {
            AIModel::query()
                ->where('model_type', AIModelType::FACE_EMBEDDING)
                ->whereKeyNot($model->getKey())
                ->update(['is_default' => false]);

            foreach ($others as $other) {
                $other->update(['status' => AIModelStatus::INACTIVE]);
            }

            $model->update([
                'status' => AIModelStatus::ACTIVE,
                'is_default' => true,
                'deployed_at' => $model->deployed_at ?? now(),
            ]);

            $audit->record(
                AuditAction::FACE_MODEL_ACTIVATED,
                null,
                'ai_model',
                $model->uuid,
                $previous,
                ['status' => AIModelStatus::ACTIVE->value, 'is_default' => true],
                [
                    'code' => $model->code,
                    'version' => $model->version,
                    'deactivated_models' => $others->map(fn (AIModel $m) => "{$m->code}@{$m->version}")->values()->all(),
                    'stranded_profiles' => $stranded,
                    'via' => 'artisan face:model-activate',
                ],
                module: 'face_biometrics',
            );
        });

        $this->info("Activated {$model->code} {$model->version}; deactivated {$others->count()} other embedding model(s).");
        if ($stranded > 0) {
            $this->warn("{$stranded} face profile(s) enrolled with other models must be re-enrolled.");
        }

        return self::SUCCESS;
    }
}
