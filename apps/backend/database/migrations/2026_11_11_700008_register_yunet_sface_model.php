<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ACR-AI-01: register the production face model (YuNet detection + SFace embedding, with
// MiniFASNet PAD on the AI service) as a DRAFT FACE_EMBEDDING model. It is not used until
// an operator runs `php artisan face:model-activate yunet-sface 2021dec`, which also
// deactivates every other embedding model so vectors of different models never mix.
//
// Stored vectors are SFace's 128 values zero-padded to the existing vector(512) column
// (cosine similarity unchanged). Recognition thresholds stay in config/face.php (FACE-021).
return new class extends Migration
{
    private const CODE = 'yunet-sface';

    private const VERSION = '2021dec';

    public function up(): void
    {
        $exists = DB::table('ai_models')
            ->where('code', self::CODE)
            ->where('version', self::VERSION)
            ->exists();

        if ($exists) {
            return;
        }

        $now = now();

        DB::table('ai_models')->insert([
            'uuid' => (string) Str::uuid7(),
            'code' => self::CODE,
            'name' => 'YuNet + SFace (OpenCV Zoo)',
            'model_type' => 'FACE_EMBEDDING',
            'version' => self::VERSION,
            'provider' => 'opencv',
            'artifact_path' => 'models/face_recognition_sface_2021dec.onnx',
            'model_checksum' => '0ba9fbfa01b5270c96627c4ef784da859931e02f04419c829e83484087c34e79',
            'embedding_dimension' => 512,
            'distance_metric' => 'COSINE',
            'threshold_high' => null,
            'threshold_low' => null,
            'status' => 'DRAFT',
            'is_default' => false,
            'config' => json_encode([
                'native_dimension' => 128,
                'storage' => 'zero-padded to 512',
                'aligned_face' => '112x112, ArcFace 5-point template',
            ]),
            'metadata' => json_encode([
                'acr' => 'ACR-AI-01',
                'detector' => [
                    'model' => 'face_detection_yunet_2023mar.onnx',
                    'sha256' => '8f2383e4dd3cfbb4553ea8718107fc0423210dc964f9f4280604804ed2552fa4',
                    'license' => 'MIT',
                ],
                'embedder' => [
                    'model' => 'face_recognition_sface_2021dec.onnx',
                    'sha256' => '0ba9fbfa01b5270c96627c4ef784da859931e02f04419c829e83484087c34e79',
                    'license' => 'Apache-2.0',
                ],
                'liveness' => [
                    'model' => 'MiniFASNet V2 (2.7) + V1SE (4.0), exported to ONNX',
                    'source_sha256' => [
                        'a5eb02e1843f19b5386b953cc4c9f011c3f985d0ee2bb9819eea9a142099bec0',
                        '84ee1d37d96894d5e82de5a57df044ef80a58be2b218b5ed7cdfd875ec2f5990',
                    ],
                    'license' => 'Apache-2.0',
                ],
                'training_data_provenance' => 'undocumented upstream; see ACR-AI-01',
            ]),
            'deployed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $id = DB::table('ai_models')
            ->where('code', self::CODE)
            ->where('version', self::VERSION)
            ->value('id');

        // Never orphan enrolled faces: keep the row once any profile uses it.
        if ($id !== null && ! DB::table('face_profiles')->where('recognition_model_id', $id)->exists()) {
            DB::table('ai_models')->where('id', $id)->delete();
        }
    }
};
