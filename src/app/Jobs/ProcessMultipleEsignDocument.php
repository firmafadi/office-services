<?php

namespace App\Jobs;

use App\Enums\MediumTypeEnum;
use App\Enums\SignatureMethodTypeEnum;
use App\Enums\SignatureQueueTypeEnum;
use App\Http\Traits\SignActionDocumentSignatureTrait;
use App\Models\DocumentSignature;
use App\Models\DocumentSignatureSent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;
use Throwable;

class ProcessMultipleEsignDocument implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use SignActionDocumentSignatureTrait;

    protected $id;
    protected $requestUserData;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($id, $requestUserData)
    {
        $this->id               = $id;
        $this->requestUserData  = $requestUserData;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->requestUserData['isSignedSelf'] == true) {
            DocumentSignature::where('id', $this->id)->update([
                'progress_queue' => SignatureQueueTypeEnum::WAITING(),
                'queue_message' => null
            ]);
        } else {
            DocumentSignatureSent::where('id', $this->id)->update([
                'progress_queue' => SignatureQueueTypeEnum::WAITING(),
                'queue_message' => null
            ]);
        }

        $documentSignatureEsignData = [
            'id' => $this->id,
            'items' => $this->requestUserData['items'],
            'userId' => $this->requestUserData['userId'],
            'passphrase' => $this->requestUserData['passphrase'],
            'esignMethod' => SignatureMethodTypeEnum::MULTIFILE(),
            'isSignedSelf' => $this->requestUserData['isSignedSelf'],
            'header' => $this->requestUserData['header'],
            'fcmToken' => $this->requestUserData['fcmToken'],
            'medium' => $this->requestUserData['medium'],
        ];

        $this->initProcessSignDocumentSignature($documentSignatureEsignData);
    }

    /**
     * Handle a job failure.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception)
    {
        if ($this->requestUserData['isSignedSelf'] == true) {
            DocumentSignature::where('id', $this->id)
                            ->update([
                                'progress_queue' => SignatureQueueTypeEnum::FAILED(),
                                'queue_message' => $exception->getMessage()
                            ]);
        } else {
            DocumentSignatureSent::where('id', $this->id)
                            ->update([
                                'progress_queue' => SignatureQueueTypeEnum::FAILED(),
                                'queue_message' => $exception->getMessage()
                            ]);
        }

        if ($this->requestUserData['medium'] == MediumTypeEnum::WEBSITE()) {
            $key = 'esign:document_upload:multifile:website:' . $this->requestUserData['userId'];
            $checkQueue = Redis::get($key);
            if (isset($checkQueue)) {
                $data = json_decode($checkQueue, true);
                if ($data['hasError'] == false) {
                    $data['hasError'] = true;
                    Redis::set($key, json_encode($data), 'EX', config('sikd.redis_exp_default'));
                }
            }
        }
    }
}
