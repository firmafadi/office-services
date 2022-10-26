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
    protected $passphrase;
    protected $userId;
    protected $fcmToken;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($documentSignatureSentId, $passphrase, $userId, $fcmToken)
    {
        $this->documentSignatureSentId  = $documentSignatureSentId;
        $this->passphrase               = $passphrase;
        $this->userId                   = $userId;
        $this->fcmToken                 = $fcmToken;
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

        DocumentSignatureSent::where('id', $documentSignatureSentId)->update([
            'progress_queue' => SignatureQueueTypeEnum::WAITING()
        ]);

        $documentSignatureEsignData = [
            'userId' => $userId,
            'fcmToken' => $fcmToken,
            'esignMethod' => SignatureMethodTypeEnum::MULTIFILE()
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
