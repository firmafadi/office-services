<?php

namespace App\Jobs;

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
use Throwable;

class ProcessMultipleEsignDocument implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use SignActionDocumentSignatureTrait;

    protected $id;
    protected $ids;
    protected $requestUserData;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($id, $ids, $requestUserData)
    {
        $this->id               = $id;
        $this->ids              = $ids;
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
                'progress_queue' => SignatureQueueTypeEnum::WAITING()
            ]);
        } else {
            DocumentSignatureSent::where('id', $this->id)->update([
                'progress_queue' => SignatureQueueTypeEnum::WAITING()
            ]);
        }

        $documentSignatureEsignData = [
            'id' => $this->id,
            'ids' => $this->ids,
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
        DocumentSignatureSent::where('id', $this->id)
                            ->update([
                                'progress_queue' => SignatureQueueTypeEnum::FAILED(),
                                'queue_message' => $exception->getMessage()
                            ]);
    }
}
