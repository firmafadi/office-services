<?php

namespace App\Jobs;

use App\Enums\SignatureMethodTypeEnum;
use App\Enums\SignatureQueueTypeEnum;
use App\Http\Traits\SignDocumentSignatureTrait;
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
    use SignDocumentSignatureTrait;

    protected $documentSignatureSentId;
    protected $requestUserData;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($documentSignatureSentId, $requestUserData)
    {
        $this->documentSignatureSentId  = $documentSignatureSentId;
        $this->passphrase               = $requestUserData['passphrase'];
        $this->userId                   = $requestUserData['userId'];
        $this->fcmToken                 = $requestUserData['fcmToken'];
        $this->header                   = $requestUserData['header'];
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $userId                   = $this->userId;
        $fcmToken                 = $this->fcmToken;
        $passphrase               = $this->passphrase;
        $documentSignatureSentId  = $this->documentSignatureSentId;
        $header                   = $this->header;

        DocumentSignatureSent::where('id', $documentSignatureSentId)->update([
            'progress_queue' => SignatureQueueTypeEnum::WAITING()
        ]);

        $documentSignatureEsignData = [
            'userId' => $userId,
            'fcmToken' => $fcmToken,
            'esignMethod' => SignatureMethodTypeEnum::MULTIFILE(),
            'header' => $header,
        ];

        $this->processSignDocumentSignature($documentSignatureSentId, $passphrase, $documentSignatureEsignData);
    }

    /**
     * Handle a job failure.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception)
    {
        DocumentSignatureSent::where('id', $this->documentSignatureSentId)
                            ->update([
                                'progress_queue' => SignatureQueueTypeEnum::FAILED()
                            ]);
    }
}
